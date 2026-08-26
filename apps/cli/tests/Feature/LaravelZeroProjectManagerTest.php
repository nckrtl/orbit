<?php

declare(strict_types=1);

use App\Support\LaravelZeroProjectManager;
use Laravel\Boost\BoostManager;
use Laravel\Boost\Install\GuidelineAssist;
use Laravel\Boost\Install\GuidelineComposer;
use Laravel\Boost\Install\GuidelineConfig;
use Laravel\Roster\ProjectManager;

describe('Laravel Zero project manager', function (): void {
    it('presents Laravel Zero as Laravel to Boost', function (): void {
        $project = app(ProjectManager::class);

        expect($project)
            ->toBeInstanceOf(LaravelZeroProjectManager::class)
            ->and($project->php()->uses('laravel-zero/framework'))
            ->toBeTrue()
            ->and($project->php()->uses('laravel/framework', '^13.0'))
            ->toBeTrue();
    });

    it('uses the Orbit entry point in Boost integrations', function (): void {
        $codexAgent = app(app(BoostManager::class)->getAgents()['codex']);
        $guidelineConfig = new GuidelineConfig;
        $guidelineConfig->aiGuidelines = [];
        $guidelineComposer = app(GuidelineComposer::class)->config($guidelineConfig);

        expect($codexAgent->getArtisanPath())
            ->toBe('orbit')
            ->and($codexAgent->getArtisanPath(true))
            ->toBe(base_path('orbit'))
            ->and(app(GuidelineAssist::class)->artisan())
            ->toBe('php orbit')
            ->and($guidelineComposer->guidelines()->has('laravel/core'))
            ->toBeFalse()
            ->and($guidelineComposer->compose())
            ->not->toContain('php artisan');
    });
});
