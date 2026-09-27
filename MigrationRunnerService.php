<?php
declare(strict_types=1);
/** Compatibility facade; Company OS 20 MigrationRunner is canonical. */
final class MigrationRunnerService {
    public const MIGRATION='companyos_20_0_final_unified';
    public static function status():array{return MigrationRunner::status('20.0');}
    public static function run(bool $withBackup=true):array{return MigrationRunner::upgrade20(null,$withBackup);}
}
