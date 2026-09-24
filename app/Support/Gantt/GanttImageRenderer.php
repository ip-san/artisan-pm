<?php

declare(strict_types=1);

namespace App\Support\Gantt;

use App\Support\Format\DateTimes;
use GdImage;
use RuntimeException;

/**
 * Redmine's Gantt#to_image (lib/redmine/helpers/gantt.rb), drawn with GD
 * instead of MiniMagick: a subject column on the left, month headers, one
 * 20px row per line with the issue's bar (grey, with its done ratio in
 * green) or the milestone's diamond, and today's date as a red line.
 * Labels are drawn with the bundled IPAGothic TrueType font (the same file
 * the PDF export uses) so Japanese subjects render.
 */
class GanttImageRenderer
{
    public const int SUBJECT_WIDTH = 400;

    public const int HEADER_HEIGHT = 18;

    public const int ROW_HEIGHT = 20;

    /**
     * Extra space under the last row, as Redmine's `20 * rows + 30` leaves.
     */
    public const int BOTTOM_MARGIN = 10;

    /**
     * The timeline is at least this wide; each day gets at least
     * MIN_DAY_WIDTH pixels (Redmine's default zoom draws 4px per day).
     */
    public const int MIN_TIMELINE_WIDTH = 800;

    public const int MIN_DAY_WIDTH = 4;

    private const float FONT_SIZE = 9.0;

    private const int INDENT = 12;

    public static function fontPath(): string
    {
        return resource_path('fonts/ipag.ttf');
    }

    public static function dayWidth(int $totalDays): int
    {
        return max(self::MIN_DAY_WIDTH, (int) ceil(self::MIN_TIMELINE_WIDTH / max(1, $totalDays)));
    }

    public static function width(int $totalDays): int
    {
        return self::SUBJECT_WIDTH + self::dayWidth($totalDays) * max(1, $totalDays) + 1;
    }

    public static function height(int $lineCount): int
    {
        return self::HEADER_HEIGHT + self::ROW_HEIGHT * $lineCount + self::BOTTOM_MARGIN;
    }

    /**
     * @param  array<int, GanttLine>  $lines
     * @return string the PNG bytes
     */
    public function render(GanttChart $chart, array $lines): string
    {
        if ($chart->isEmpty()) {
            throw new RuntimeException('An empty Gantt chart has nothing to draw.');
        }

        $totalDays = $chart->totalDays();
        $dayWidth = self::dayWidth($totalDays);
        $width = self::width($totalDays);
        $height = self::height(count($lines));
        $timelineWidth = $width - self::SUBJECT_WIDTH - 1;

        $image = imagecreatetruecolor($width, $height);
        $white = $this->color($image, '#ffffff');
        $black = $this->color($image, '#000000');
        $grey = $this->color($image, '#808080');
        $lightGrey = $this->color($image, '#eeeeee');

        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, $white);

        // Subjects first: the timeline's background is painted over any
        // label running past the subject column, which clips it.
        foreach ($lines as $index => $line) {
            $top = self::HEADER_HEIGHT + $index * self::ROW_HEIGHT;
            $this->text($image, 4 + $line->depth * self::INDENT, $top + 14, $this->subjectFor($line), $black);
        }

        imagefilledrectangle($image, self::SUBJECT_WIDTH, 0, $width - 1, $height - 1, $white);

        foreach ($chart->monthBands() as $band) {
            $left = self::SUBJECT_WIDTH + (int) round($band['leftPercent'] / 100 * $timelineWidth);
            $right = self::SUBJECT_WIDTH + (int) round(($band['leftPercent'] + $band['widthPercent']) / 100 * $timelineWidth);
            imagerectangle($image, $left, 0, $right, $height - 1, $grey);
            $this->text($image, $left + 4, 13, $band['label'], $black);
        }

        foreach ($lines as $index => $line) {
            $top = self::HEADER_HEIGHT + $index * self::ROW_HEIGHT;
            $middle = $top + intdiv(self::ROW_HEIGHT, 2);

            if ($line->kind === GanttLine::PROJECT) {
                imagefilledrectangle($image, self::SUBJECT_WIDTH + 1, $top + 1, $width - 2, $top + self::ROW_HEIGHT - 1, $lightGrey);
            } elseif ($line->kind === GanttLine::ISSUE && $line->row?->hasDateRange()) {
                $this->drawBar($image, $chart, $line->row, $timelineWidth, $middle);
            } elseif ($line->kind === GanttLine::VERSION && $line->version?->due_date !== null) {
                $x = self::SUBJECT_WIDTH + (int) round($chart->versionMarkerLeftPercent($line->version) / 100 * $timelineWidth);
                imagefilledpolygon($image, [$x - 5, $middle, $x, $middle - 5, $x + 5, $middle, $x, $middle + 5], $this->color($image, '#fca700'));
                $this->text($image, $x + 8, $middle + 4, "{$line->label} {$line->versionPercent}%", $black);
            }
        }

        $today = DateTimes::today();

        if ($today->between($chart->rangeStart, $chart->rangeEnd)) {
            $x = self::SUBJECT_WIDTH + (int) round($chart->percentFromStart($today) / 100 * $timelineWidth) + intdiv($dayWidth, 2);
            imageline($image, $x, self::HEADER_HEIGHT, $x, $height - 1, $this->color($image, '#ff0000'));
        }

        imageline($image, 0, self::HEADER_HEIGHT, $width - 1, self::HEADER_HEIGHT, $grey);
        imagerectangle($image, 0, 0, $width - 1, $height - 1, $black);

        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    private function subjectFor(GanttLine $line): string
    {
        return $line->kind === GanttLine::VERSION ? "◆ {$line->label}" : $line->label;
    }

    private function drawBar(GdImage $image, GanttChart $chart, GanttRow $row, int $timelineWidth, int $middle): void
    {
        $left = self::SUBJECT_WIDTH + (int) round($chart->barLeftPercent($row) / 100 * $timelineWidth);
        $barWidth = max(1, (int) round($chart->barWidthPercent($row) / 100 * $timelineWidth));

        imagefilledrectangle($image, $left, $middle - 4, $left + $barWidth - 1, $middle + 3, $this->color($image, $row->isClosed ? '#cccccc' : '#aaaaaa'));

        if ($row->doneRatio > 0) {
            $done = max(1, (int) round($barWidth * min(100, $row->doneRatio) / 100));
            imagefilledrectangle($image, $left, $middle - 4, $left + $done - 1, $middle + 3, $this->color($image, '#00c600'));
        }

        imagettftext($image, self::FONT_SIZE, 0, $left + $barWidth + 4, $middle + 4, $this->color($image, '#000000'), self::fontPath(), "{$row->statusName} {$row->doneRatio}%");
    }

    private function text(GdImage $image, int $x, int $baseline, string $text, int $color): void
    {
        imagettftext($image, self::FONT_SIZE, 0, $x, $baseline, $color, self::fontPath(), $text);
    }

    private function color(GdImage $image, string $hex): int
    {
        [$red, $green, $blue] = sscanf($hex, '#%02x%02x%02x');

        return (int) imagecolorallocate($image, $red, $green, $blue);
    }
}
