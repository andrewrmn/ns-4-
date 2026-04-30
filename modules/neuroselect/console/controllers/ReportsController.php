<?php

namespace modules\neuroselect\console\controllers;

use Craft;
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
        $perFieldFetch = max($limit * 5, 250);

        $sources = [
            ['label' => 'Neuro Q', 'handle' => 'surveySubmissions', 'preferRowDate' => false],
            ['label' => 'NeuroSelect · pathway', 'handle' => 'pathwaySubmissions', 'preferRowDate' => true],
            ['label' => 'NeuroSelect · clinical indication', 'handle' => 'clinicalIndicationSubmission', 'preferRowDate' => true],
            ['label' => 'NeuroSelect · product', 'handle' => 'productSubmission', 'preferRowDate' => true],
            ['label' => 'NeuroSelect · sleep', 'handle' => 'sleepSubmission', 'preferRowDate' => true],
            ['label' => 'NeuroSelect · NeuroCore', 'handle' => 'neuroCoreSubmissions', 'preferRowDate' => true],
            ['label' => 'NeuroSelect · QR scan', 'handle' => 'qrScanSubmissions', 'preferRowDate' => true],
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

        $this->stdout(sprintf("Most recent %d report rows (Neuro Q + NeuroSelect), site TZ %s\n\n", count($rows), Craft::$app->getTimeZone()));

        foreach ($rows as $i => $r) {
            $this->stdout(sprintf(
                "%3d. %-38s  %-12s  %s\n",
                $i + 1,
                $r['label'],
                $r['mdy'],
                $r['email']
            ));
        }

        $this->stdout("\nNotes: Neuro Q uses each block’s save time (no separate date field). NeuroSelect prefers the row “date” field when it parses; otherwise block save time.\n");

        return ExitCode::OK;
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
