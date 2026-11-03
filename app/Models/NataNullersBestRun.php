<?php

namespace App\Models;

use App\Devnull\NataNullers\Run;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Your best Nata Nu//ers run, one per user, kept in the app's database so it
 * outlives app updates (see App\Devnull\NataNullers\HighScore).
 *
 * $ended_at is the Unix time the run ended, as it was signed.
 *
 * @property int $id
 * @property string $user_id
 * @property string $run_id
 * @property int $score
 * @property int $ended_at
 * @property string $signature
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class NataNullersBestRun extends Model
{
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'ended_at' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * The columns that hold a run.
     *
     * @return array{run_id: string, score: int, ended_at: int, signature: string}
     */
    public static function columnsFor(Run $run): array
    {
        return ['run_id' => $run->id, 'score' => $run->score, 'ended_at' => $run->endedAt, 'signature' => $run->signature];
    }

    public function run(): Run
    {
        return new Run($this->run_id, $this->user_id, $this->score, $this->ended_at, $this->signature);
    }
}
