<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Race;
use App\Motivation;
use App\Shift;
use App\Accrual;
use App\Report;
use Carbon\Carbon;

class CalculateMotivationPositionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $today = Carbon::today()->subDay()->format('Y-m-d');
        $race = Race::where('started_at', 'like', $today.'%')->first();
        $shifts = Shift::getTodayMotivityShifts($race->shifts->first())->get();
        $motivations = Motivation::all();
        $motivations->each(function($motivation) use ($shifts, $race) {
            $positionsArray = $motivation->getPositionsArray($shifts, $motivation->sys_mark);
            $positionsArray->each(function($item, $key) use ($motivation, $race){
                $shift = Shift::find($item['shift_id']);
                if(!$shift->isOpened() && $key == 0 && $item['percentage'] > 0) {
                    $shift->accruals()->save(new Accrual(['amount' => $motivation->reward, 'type' => 'В т.ч. за мотивити ' . $motivation->title, 'recipient_type' => 'master_motivity']));
                    $race->motivations()
                    ->attach([$motivation->id], [
                        'position' => $item['percentage'] == 0 ? 0 : $key + 1,
                        'shift_id' => $item['shift_id'],
                        'reward' => 0
                        ]);
                        Report::sendMotivityRewardChargedToAccrualsReport($motivation, $item);
                } else {
                    $race->motivations()
                    ->attach([$motivation->id], [
                        'position' => $item['percentage'] == 0 ? 0 : $key + 1,
                        'shift_id' => $item['shift_id'],
                        'reward' => $key == 0 && $item['percentage'] > 0 ? $motivation->reward : 0
                        ]);
                }
            });
        });
    }
}
