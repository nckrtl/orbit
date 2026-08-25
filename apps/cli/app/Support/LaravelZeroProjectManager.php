<?php

declare(strict_types=1);

namespace App\Support;

use Laravel\Roster\Ecosystems\Ecosystem;
use Laravel\Roster\Enums\PackageSource;
use Laravel\Roster\Package;
use Laravel\Roster\PackageCollection;
use Laravel\Roster\ProjectManager;

final class LaravelZeroProjectManager extends ProjectManager
{
    private ?Ecosystem $phpEcosystem = null;

    public function php(): Ecosystem
    {
        if ($this->phpEcosystem instanceof Ecosystem) {
            return $this->phpEcosystem;
        }

        $phpEcosystem = parent::php();
        $laravelZero = $phpEcosystem->package('laravel-zero/framework');

        if ($phpEcosystem->uses('laravel/framework') || ! $laravelZero instanceof Package) {
            return $this->phpEcosystem = $phpEcosystem;
        }

        $packages = new PackageCollection($phpEcosystem->packages()->all());
        $packages->push(new Package(
            name: 'laravel/framework',
            version: $laravelZero->version(),
            source: PackageSource::Composer,
            dev: $laravelZero->isDev(),
            direct: $laravelZero->isDirect(),
            constraint: $laravelZero->constraint(),
            path: $laravelZero->path(),
        ));

        return $this->phpEcosystem = new Ecosystem($packages);
    }
}
