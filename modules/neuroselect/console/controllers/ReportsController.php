<?php

namespace modules\neuroselect\console\controllers;

use Craft;
use craft\elements\Asset;
use craft\elements\User;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;
use verbb\supertable\elements\SuperTableBlockElement;
use yii\console\Controller;
use yii\console\ExitCode;

/**
 * Dev-only inspection of Neuro Q + NeuroSelect stored submissions (Super Table rows).
 *
 * ./craft neuroselect-module/reports/recent-reports
 * ./craft neuroselect-module/reports/recent-reports --limit=50
 *
 * Only includes rows where a PDF exists: NeuroSelect uses the “PDF Generated” lightswitch;
 * Neuro Q checks for the survey PDF asset (same as the report templates); NeuroCore uses a non-empty report URL.
 */
class ReportsController extends Controller
{
    public int $limit = 100;

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['limit']);
    }

    /**
     * @inheritdoc
     */
    public function optionAliases(): array
    {
        return array_merge(parent::optionAliases(), ['l' => 'limit']);
    }

    public function actionRecentReports(): int
    {
        $limit = max(1, min(5000, $this->limit));
        $perFieldFetch = max($limit * 40, 1200);

        $sources = [
            ['label' => 'Neuro Q', 'handle' => 'surveySubmissions', 'preferRowDate' => false, 'pdfMode' => 'neuroq_asset'],
            ['label' => 'NeuroSelect · pathway', 'handle' => 'pathwaySubmissions', 'preferRowDate' => true, 'pdfMode' => 'pdf_generated'],
            ['label' => 'NeuroSelect · clinical indication', 'handle' => 'clinicalIndicationSubmission', 'preferRowDate' => true, 'pdfMode' => 'pdf_generated'],
            ['label' => 'NeuroSelect · product', 'handle' => 'productSubmission', 'preferRowDate' => true, 'pdfMode' => 'pdf_generated'],
            ['label' => 'NeuroSelect · sleep', 'handle' => 'sleepSubmission', 'preferRowDate' => true, 'pdfMode' => 'pdf_generated'],
            ['label' => 'NeuroSelect · NeuroCore', 'handle' => 'neuroCoreSubmissions', 'preferRowDate' => true, 'pdfMode' => 'report_url'],
            ['label' => 'NeuroSelect · QR scan', 'handle' => 'qrScanSubmissions', 'preferRowDate' => true, 'pdfMode' => 'pdf_generated'],
        ];

        $rows = [];

        foreach ($sources as $src) {
            $field = Craft::$app->getFields()->getFieldByHandle($src['handle']);
            if ($field === null) {
                continue;
            }

            $blocks = SuperTableBlockElement::find()
                ->fieldId($field->id)
                ->orderBy(['elements.dateCreated' => SORT_DESC])
                ->limit($perFieldFetch)
                ->with(['owner'])
                ->all();

            foreach ($blocks as $block) {
                if (!$this->hasPdfForReport($block, (string) $src['pdfMode'])) {
                    continue;
                }
                [$ts, $dt] = $this->resolveInstant($block, (bool) $src['preferRowDate']);
                $rows[] = [
                    'ts' => $ts,
                    'label' => $src['label'],
                    'email' => $this->resolveEmail($block),
                    'mdy' => $this->formatMdy($dt),
                ];
            }
        }

        usort($rows, static fn (array $a, array $b): int => $b['ts'] <=> $a['ts']);
        $rows = array_slice($rows, 0, $limit);

        $this->stdout(sprintf("Most recent %d rows with PDF (Neuro Q + NeuroSelect), site TZ %s\n\n", count($rows), Craft::$app->getTimeZone()));

        foreach ($rows as $i => $r) {
            $this->stdout(sprintf(
                "%3d. %-38s  %-12s  %s\n",
                $i + 1,
                $r['label'],
                $r['mdy'],
                $r['email']
            ));
        }

        $this->stdout("\nNotes: PDF = NeuroSelect “PDF Generated” on (except NeuroCore: non-empty report URL; Neuro Q: NS-SURVEY-{id}.pdf in volume folder id 22 per report templates). Dates: Neuro Q uses block save time; NeuroSelect prefers row “date” when parseable.\n");

        return ExitCode::OK;
    }

    /**
     * @param 'neuroq_asset'|'pdf_generated'|'report_url' $pdfMode
     */
    private function hasPdfForReport(SuperTableBlockElement $block, string $pdfMode): bool
    {
        return match ($pdfMode) {
            'neuroq_asset' => $this->neuroSurveyPdfAssetExists($block),
            'pdf_generated' => $this->isPdfGeneratedLightswitchOn($block),
            'report_url' => $this->neuroCoreReportUrlPresent($block),
            default => false,
        };
    }

    /**
     * Matches `templates/neuroselect/survey/report.html` (folderId 22, NS-SURVEY-{submissionId}.pdf).
     */
    private function neuroSurveyPdfAssetExists(SuperTableBlockElement $block): bool
    {
        try {
            $submissionId = $block->getFieldValue('submissionId');
        } catch (Throwable) {
            return false;
        }

        if (!is_string($submissionId) || $submissionId === '') {
            return false;
        }

        $filename = 'NS-SURVEY-' . $submissionId . '.pdf';

        return Asset::find()
            ->filename($filename)
            ->folderId(22)
            ->limit(1)
            ->exists();
    }

    private function isPdfGeneratedLightswitchOn(SuperTableBlockElement $block): bool
    {
        try {
            $v = $block->getFieldValue('pdfGenerated');
        } catch (Throwable) {
            return false;
        }

        return $v === true || $v === 1 || $v === '1';
    }

    private function neuroCoreReportUrlPresent(SuperTableBlockElement $block): bool
    {
        try {
            $url = $block->getFieldValue('reportUrl');
        } catch (Throwable) {
            return false;
        }

        return is_string($url) && trim($url) !== '';
    }

    /**
     * @return array{0: int, 1: DateTimeImmutable}
     */
    private function resolveInstant(SuperTableBlockElement $block, bool $preferRowDate): array
    {
        $tz = new DateTimeZone(Craft::$app->getTimeZone());
        $fallback = DateTimeImmutable::createFromMutable($block->dateCreated)->setTimezone($tz);

        if ($preferRowDate) {
            $parsed = $this->parseOptionalRowDate($block, $tz);
            if ($parsed !== null) {
                return [$parsed->getTimestamp(), $parsed];
            }
        }

        return [$fallback->getTimestamp(), $fallback];
    }

    private function parseOptionalRowDate(SuperTableBlockElement $block, DateTimeZone $tz): ?DateTimeImmutable
    {
        try {
            $raw = $block->getFieldValue('date');
        } catch (Throwable) {
            return null;
        }

        if ($raw === null || $raw === '') {
            return null;
        }

        if ($raw instanceof \DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($raw)->setTimezone($tz);
        }

        if (!is_string($raw)) {
            return null;
        }

        try {
            $dt = new DateTimeImmutable($raw, $tz);
        } catch (\Exception) {
            $ts = strtotime($raw);
            if ($ts === false) {
                return null;
            }
            $dt = (new DateTimeImmutable('@' . $ts))->setTimezone($tz);
        }

        return $dt;
    }

    private function resolveEmail(SuperTableBlockElement $block): string
    {
        $owner = $block->getOwner();
        if ($owner instanceof User) {
            return $owner->email ?: '(user without email)';
        }

        try {
            $guest = $block->getFieldValue('email');
            if (is_string($guest) && $guest !== '') {
                return $guest . ' (guest / non-user owner)';
            }
        } catch (Throwable) {
        }

        $oid = $owner->id ?? '?';

        return '(owner element #' . $oid . ', not a User)';
    }

    private function formatMdy(DateTimeImmutable $dt): string
    {
        return $dt->format('m/d/Y');
    }
}
