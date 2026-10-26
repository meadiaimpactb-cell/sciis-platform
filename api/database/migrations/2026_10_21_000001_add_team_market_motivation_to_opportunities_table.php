<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ENT-01 — accelerator-grade application sections feeding the evaluation "readiness" axis (doc §12.5):
 *  - team (confidential "س"): founders & team composition + a repeatable founders list (JSON, max 6);
 *  - market (mixed): customers / market size / competition / go-to-market / product stage / technology / traction /
 *    scalability — only product_stage, target_customers, competitive_advantage and traction_desc are public;
 *  - motivation (internal "د"): why now, support needed (JSON keys), programme expectations, acquisition channel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('opportunities', function (Blueprint $table): void {
            // Team & founders (confidential).
            $table->unsignedSmallInteger('founders_count')->nullable()->after('council_relationship_desc');
            $table->unsignedInteger('team_size')->nullable()->after('founders_count');
            $table->string('founders_commitment', 20)->nullable()->after('team_size'); // full_time|part_time|transitioning
            $table->string('founders_commitment_note', 500)->nullable()->after('founders_commitment');
            $table->boolean('prior_startup_experience')->nullable()->after('founders_commitment_note');
            $table->text('prior_startup_experience_desc')->nullable()->after('prior_startup_experience');
            $table->boolean('industry_experience')->nullable()->after('prior_startup_experience_desc');
            $table->text('industry_experience_desc')->nullable()->after('industry_experience');
            $table->string('founders_known_years', 10)->nullable()->after('industry_experience_desc'); // lt_1|1_3|3_5|5_plus
            $table->boolean('has_advisory_board')->nullable()->after('founders_known_years');
            $table->text('advisory_board_desc')->nullable()->after('has_advisory_board');
            $table->string('dev_team_structure', 20)->nullable()->after('advisory_board_desc'); // in_house|outsourced|hybrid|none
            $table->boolean('founders_agreement_signed')->nullable()->after('dev_team_structure');
            $table->json('founders')->nullable()->after('founders_agreement_signed'); // [{name, role, linkedin_url, is_full_time}]

            // Market & product.
            $table->text('target_customers')->nullable()->after('founders'); // public
            $table->text('market_size_desc')->nullable()->after('target_customers');
            $table->text('competitors')->nullable()->after('market_size_desc');
            $table->text('competitive_advantage')->nullable()->after('competitors'); // public
            $table->text('go_to_market')->nullable()->after('competitive_advantage');
            $table->string('product_stage', 20)->nullable()->after('go_to_market'); // public: idea|prototype|mvp|launched|scaling
            $table->text('technology_desc')->nullable()->after('product_stage');
            $table->text('traction_desc')->nullable()->after('technology_desc'); // public
            $table->text('scalability_desc')->nullable()->after('traction_desc');

            // Motivation & commitment (internal).
            $table->text('why_now')->nullable()->after('scalability_desc');
            $table->json('support_needed')->nullable()->after('why_now'); // keys from Opportunity::SUPPORT_NEEDS
            $table->text('program_expectations')->nullable()->after('support_needed');
            $table->foreignId('how_heard_id')->nullable()->after('program_expectations')->constrained('taxonomy_terms')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('opportunities', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('how_heard_id');
            $table->dropColumn([
                'founders_count', 'team_size', 'founders_commitment', 'founders_commitment_note', 'prior_startup_experience',
                'prior_startup_experience_desc', 'industry_experience', 'industry_experience_desc', 'founders_known_years',
                'has_advisory_board', 'advisory_board_desc', 'dev_team_structure', 'founders_agreement_signed', 'founders',
                'target_customers', 'market_size_desc', 'competitors', 'competitive_advantage', 'go_to_market', 'product_stage',
                'technology_desc', 'traction_desc', 'scalability_desc', 'why_now', 'support_needed', 'program_expectations',
            ]);
        });
    }
};
