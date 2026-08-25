<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\ScheduleFinishCommand;
use Illuminate\Console\Scheduling\ScheduleListCommand;
use Illuminate\Console\Scheduling\ScheduleRunCommand;
use Illuminate\Foundation\Console\VendorPublishCommand;
use Laravel\Boost\Console\AddSkillCommand;
use Laravel\Boost\Console\InstallCommand as BoostInstallCommand;
use Laravel\Boost\Console\ListSkillCommand;
use Laravel\Boost\Console\StartCommand as BoostStartCommand;
use Laravel\Boost\Console\UpdateCommand;
use Laravel\Mcp\Console\Commands\InspectorCommand;
use Laravel\Mcp\Console\Commands\MakeAppResourceCommand;
use Laravel\Mcp\Console\Commands\MakePromptCommand;
use Laravel\Mcp\Console\Commands\MakeResourceCommand;
use Laravel\Mcp\Console\Commands\MakeServerCommand;
use Laravel\Mcp\Console\Commands\MakeToolCommand;
use Laravel\Mcp\Console\Commands\StartCommand as McpStartCommand;
use LaravelZero\Framework\Commands\BuildCommand;
use LaravelZero\Framework\Commands\InstallCommand;
use LaravelZero\Framework\Commands\MakeCommand;
use LaravelZero\Framework\Commands\RenameCommand;
use LaravelZero\Framework\Commands\StubPublishCommand;
use LaravelZero\Framework\Commands\TestMakeCommand;
use NunoMaduro\Collision\Adapters\Laravel\Commands\TestCommand;
use NunoMaduro\LaravelConsoleSummary\SummaryCommand;
use Symfony\Component\Console\Command\DumpCompletionCommand;
use Symfony\Component\Console\Command\HelpCommand;

return [
    /*
     |--------------------------------------------------------------------------
     | Default Command
     |--------------------------------------------------------------------------
     |
     | Laravel Zero will always run the command specified below when no command name is
     | provided. Consider update the default command for single command applications.
     | You cannot pass arguments to the default command because they are ignored.
     |
     */

    'default' => SummaryCommand::class,

    /*
     |--------------------------------------------------------------------------
     | Commands Paths
     |--------------------------------------------------------------------------
     |
     | This value determines the "paths" that should be loaded by the console's
     | kernel. Foreach "path" present on the array provided below the kernel
     | will extract all "Illuminate\Console\Command" based class commands.
     |
     */

    'paths' => [app_path('Commands')],

    /*
     |--------------------------------------------------------------------------
     | Added Commands
     |--------------------------------------------------------------------------
     |
     | You may want to include a single command class without having to load an
     | entire folder. Here you can specify which commands should be added to
     | your list of commands. The console's kernel will try to load them.
     |
     */

    'add' => [],

    /*
     |--------------------------------------------------------------------------
     | Hidden Commands
     |--------------------------------------------------------------------------
     |
     | Your application commands will always be visible on the application list
     | of commands. But you can still make them "hidden" specifying an array
     | of commands below. All "hidden" commands can still be run/executed.
     |
     */

    'hidden' => [
        SummaryCommand::class,
        DumpCompletionCommand::class,
        HelpCommand::class,
        ScheduleRunCommand::class,
        ScheduleListCommand::class,
        ScheduleFinishCommand::class,
        VendorPublishCommand::class,
        StubPublishCommand::class,
        AddSkillCommand::class,
        BoostInstallCommand::class,
        ListSkillCommand::class,
        BoostStartCommand::class,
        UpdateCommand::class,
        InspectorCommand::class,
        MakeAppResourceCommand::class,
        MakePromptCommand::class,
        MakeResourceCommand::class,
        MakeServerCommand::class,
        MakeToolCommand::class,
        McpStartCommand::class,
    ],

    /*
     |--------------------------------------------------------------------------
     | Removed Commands
     |--------------------------------------------------------------------------
     |
     | Do you have a service provider that loads a list of commands that
     | you don't need? No problem. Laravel Zero allows you to specify
     | below a list of commands that you don't to see in your app.
     |
     */

    'remove' => [
        BuildCommand::class,
        InstallCommand::class,
        MakeCommand::class,
        RenameCommand::class,
        TestCommand::class,
        TestMakeCommand::class,
    ],
];
