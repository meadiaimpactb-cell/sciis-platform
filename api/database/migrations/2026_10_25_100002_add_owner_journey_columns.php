<?php

declare(strict_types=1);

use App\Enums\OpportunityStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Owner journey columns:
 *  - evaluation_decisions.improvement_plan — EVL-03 structured plan `[{text, due_at}]` shown to the owner;
 *  - info_requests.closed_at / closed_by — §6.3 the "closed" status;
 *  - completion_requests.reminders_sent — journey 11.3, reminder thresholds already sent to the owner;
 *  - opportunities.stage_due_at — ADM-03 SLA deadline of the post-evaluation stages (eligible, connected, negotiating).
 */
return new class extends Migration
{
    /** status → setting key / default days (mirrors OpportunityStateMachine::STAGE_SLA). */
    private const STAGE_SLA = [
        'eligible' => ['sla.matching_days', 30],
        'connected' => ['sla.connection_days', 30],
        'negotiating' => ['sla.negotiation_days', 60],
    ];

    public function up(): void
    {
        Schema::table('evaluation_decisions', function (Blueprint $table): void {
            $table->json('improvement_plan')->nullable()->after('reasons');
        });

        Schema::table('info_requests', function (Blueprint $table): void {
            $table->timestamp('closed_at')->nullable()->after('answered_at');
            $table->unsignedBigInteger('closed_by')->nullable()->after('closed_at');
            if (DB::getDriverName() !== 'sqlite') {
                $table->foreign('closed_by')->references('id')->on('users')->nullOnDelete();
            }
        });

        Schema::table('completion_requests', function (Blueprint $table): void {
            $table->json('reminders_sent')->nullable()->after('resolved_at');
        });

        Schema::table('opportunities', function (Blueprint $table): void {
            $table->timestamp('stage_due_at')->nullable()->after('evaluation_due_at')->index();
        });

        $this->backfillStageDueAt();
    }

    public function down(): void
    {
        Schema::table('opportunities', function (Blueprint $table): void {
            $table->dropIndex(['stage_due_at']);
            $table->dropColumn('stage_due_at');
        });

        Schema::table('completion_requests', function (Blueprint $table): void {
            $table->dropColumn('reminders_sent');
        });

        Schema::table('info_requests', function (Blueprint $table): void {
            if (DB::getDriverName() !== 'sqlite') {
                $table->dropForeign(['closed_by']);
            }
            $table->dropColumn(['closed_at', 'closed_by']);
        });

        Schema::table('evaluation_decisions', function (Blueprint $table): void {
            $table->dropColumn('improvement_plan');
        });
    }

    /** Existing opportunities in a post-evaluation stage get a deadline counted from the time they entered it. */
    private function backfillStageDueAt(): void
    {
        foreach (self::STAGE_SLA as $status => [$key, $default]) {
            $stored = DB::table('settings')->where('key', $key)->value('value');
            $days = $stored !== null ? (int) json_decode((string) $stored, true) : $default;
            $days = $days > 0 ? $days : $default;

            DB::table('opportunities')->where('status', OpportunityStatus::from($status)->value)->whereNull('stage_due_at')
                ->orderBy('id')
                ->select(['id', 'updated_at'])
                ->chunkById(200, function ($rows) use ($status, $days): void {
                    foreach ($rows as $row) {
                        $entered = DB::table('opportunity_status_transitions')
                            ->where('opportunity_id', $row->id)->where('to_status', $status)
                            ->max('created_at') ?? $row->updated_at ?? now();

                        DB::table('opportunities')->where('id', $row->id)
                            ->update(['stage_due_at' => Carbon::parse($entered)->addDays($days)]);
                    }
                });
        }
    }
};
