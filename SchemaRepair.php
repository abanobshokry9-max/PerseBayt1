<?php
declare(strict_types=1);

/**
 * Clean releases never mutate the core schema in-place.
 * A schema mismatch means the uploaded files and the single-import SQL do not match.
 */
final class SchemaRepair
{
    public static function applyPackageUpgrade(): array
    {
        $issues = SystemDoctor::schemaIssues();
        return [
            'ok' => !$issues,
            'state' => $issues ? 'database_release_mismatch' : 'schema_ok',
            'release' => ReleaseInfo::VERSION,
            'schema' => ReleaseInfo::SCHEMA,
            'issues' => $issues,
            'message' => $issues
                ? 'هذه نسخة Clean Release ولا تنفذ ترقيات داخلية. استورد ملف قاعدة البيانات المطابق للإصدار ثم أعد الفحص.'
                : 'بنية قاعدة البيانات مطابقة للإصدار الحالي.',
        ];
    }
}
