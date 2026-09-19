<?php

declare(strict_types=1);

namespace App\Concerns;

/**
 * Lets a list that keeps its displayed columns in a `$columns` array change
 * their order — Redmine's column picker allows reordering, and both the
 * table and CSV export (and a saved query's column_names) follow that array.
 * Only columns already selected can move, so the client cannot use this to
 * inject an arbitrary key.
 */
trait ReordersColumns
{
    /**
     * @param  int  $delta  only its sign matters: negative moves the column
     *                      one place earlier, positive one place later
     */
    public function moveColumn(string $key, int $delta): void
    {
        $index = array_search($key, $this->columns, true);

        if ($index === false) {
            return;
        }

        $target = $index + ($delta < 0 ? -1 : 1);

        if ($target < 0 || $target >= count($this->columns)) {
            return;
        }

        [$this->columns[$index], $this->columns[$target]] = [$this->columns[$target], $this->columns[$index]];
    }
}
