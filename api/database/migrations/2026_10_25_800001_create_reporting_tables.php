<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reporting & admin productivity:
 *  - saved_reports — RPT-04 named report definitions (dataset + columns + filters + grouping), RPT-05 schedule;
 *  - periodic_reports — journey 11.10 KPI snapshot drafted → approved (reports.approve) → published to an audience;
 *  - memberships.assigned_reviewer_id — ADM-08 bulk reviewer assignment on the membership queue;
 *  - advisory_referrals.referred_by — EVL-04 who referred the file (notified when the opinion arrives).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_reports', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name', 150);
            $table->string('dataset', 40);
            $table->json('definition'); // {columns: [], filters: {}, group_by: ?string}
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('schedule_frequency', 10)->nullable(); // weekly | monthly
            $table->string('schedule_format', 5)->default('xlsx'); // xlsx | pdf
            $table->json('schedule_recipients')->nullable(); // staff user ids
            $table->timestamp('next_run_at')->nullable()->index();
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamps();
        });

        Schema::create('periodic_reports', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('period_type', 12); // monthly | quarterly | annual
            $table->date('period_start');
            $table->date('period_end');
            $table->string('title', 190);
            $table->text('summary')->nullable();
            $table->json('snapshot');
            $table->string('status', 20)->default('draft')->index(); // draft | approved | published
            $table->string('audience', 10)->default('staff'); // staff | public
            $table->text('review_note')->nullable();
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('generated_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->unique(['period_type', 'period_start']);
        });

        Schema::table('memberships', function (Blueprint $table): void {
            $table->unsignedBigInteger('assigned_reviewer_id')->nullable()->after('status')->index();
            if (DB::getDriverName() !== 'sqlite') {
                $table->foreign('assigned_reviewer_id')->references('id')->on('users')->nullOnDelete();
            }
        });

        Schema::table('advisory_referrals', function (Blueprint $table): void {
            $table->unsignedBigInteger('referred_by')->nullable()->after('advisory_user_id');
            if (DB::getDriverName() !== 'sqlite') {
                $table->foreign('referred_by')->references('id')->on('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('advisory_referrals', function (Blueprint $table): void {
            if (DB::getDriverName() !== 'sqlite') {
                $table->dropForeign(['referred_by']);
            }
            $table->dropColumn('referred_by');
        });

        Schema::table('memberships', function (Blueprint $table): void {
            if (DB::getDriverName() !== 'sqlite') {
                $table->dropForeign(['assigned_reviewer_id']);
            }
            $table->dropIndex(['assigned_reviewer_id']);
            $table->dropColumn('assigned_reviewer_id');
        });

        Schema::dropIfExists('periodic_reports');
        Schema::dropIfExists('saved_reports');
    }
};
