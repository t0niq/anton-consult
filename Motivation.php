<?php

namespace App;

use App\Models\Service\Common\AverageReceipt\AverageReceipt;
use Illuminate\Database\Eloquent\Model;

class Motivation extends Model
{
    protected $guarded = [];

    public function path()
    {
        return "/backoffice/motivations/{$this->id}";
    }

    public function scopeEnabled($query)
    {
        return $query->where('enabled', 1);
    }

    public function scopeSimple($query)
    {
        return $query->whereNull('sys_mark');
    }

    public function participants()
    {
        return $this->belongsToMany(User::class);
    }

    public function services()
    {
        return $this->belongsToMany(Service::class);
    }

    public function points()
    {
       return $this->belongsToMany(Shift::class)
       ->withPivot('id', 'order_id');
    }

    public function races()
    {
        return $this->belongsToMany(Race::class)->withPivot('id', 'shift_id', 'position', 'reward');
    }

    public function averageReceipt()
    {
        return $this->morphOne(AverageReceipt::class, 'hoster');
    }

    public function isParticipant(User $user)
    {
        return $this->participants->contains($user->id) ? true : false;
    }

    public function percentOfMotivation(Shift $shift, $precise = null)
    {
        $shiftCars = $shift->shiftCars(true);

        if($this->points->where('id', $shift->id)->count() > 0 && $shiftCars > 0){
            if($precise) {
                return $this->points->where('id', $shift->id)->count()/$shiftCars*100;
            }
            if($shiftCars) {
                return number_format((float)$this->points->where('id', $shift->id)->count()/$shiftCars*100, 0, '.', '');
            }
        }
        return 0;
    }

    public function averageShiftReceipt(Shift $shift)
    {
        if($this->averageReceipt) {
            $shiftCars = $shift->orders()->paid()->get()->sum->averageReceiptCount($this->averageReceipt);
            if($this->isParticipant($shift->employee->user) && $shift->orders->count() > 0 && $shiftCars > 0) {
                return number_format((float)$shift->orders()->paid()->get()->sum->averageReceiptSum($this->averageReceipt) / $shiftCars, 0, '.', '');
            }
            return 0;
        }
        return $this->oldAverageShiftReceipt($shift);
    }

    public function oldAverageShiftReceipt(Shift $shift)
    {
        $shiftCars = $shift->shiftCars(true);
        if($this->isParticipant($shift->employee->user) && $shift->orders->count() > 0 && $shiftCars > 0) {
            $servicesInMotivitation = $this->services->pluck('id');
            $ordersSum = 0;

            $shift->orders->where('status', '=', 'paid')->each(function($order) use (&$ordersSum, $servicesInMotivitation) {
                $order->services->whereIn('id', $servicesInMotivitation)->each(function($service) use (&$ordersSum) {
                    if($service->pivot->price == '0.00') {
                        $ordersSum += $service->pivot->original_price * $service->pivot->quantity - $service->pivot->master_bonus - $service->pivot->moneybox_sallary;
                    } else {
                        $ordersSum += $service->pivot->price * $service->pivot->quantity - $service->pivot->master_bonus - $service->pivot->moneybox_sallary;
                    }
                });
            });

            return number_format((float)$ordersSum/$shiftCars, 0, '.', '');
        }
        return 0;
    }

    public function getPositionsArray($shiftsCollection, $motivationType = null)
    {
        $positionsCollection = collect();

        $shiftsCollection->each(function($shift) use(&$positionsCollection, $motivationType) {
            if ($motivationType == 'averageShiftReceipt') {
                $positionsCollection->push(['percentage' => $this->averageShiftReceipt($shift), 'shift_id' => $shift->id]);
            } else {
                $positionsCollection->push(['percentage' => $this->percentOfMotivation($shift, 1), 'shift_id' => $shift->id]);
            }
        });

        return $positionsCollection->sortByDesc('percentage')->values();

    }

    public function getPosition(Shift $shift, $positionsCollection)
    {
        $position = $positionsCollection->search(function($item, $key) use ($shift) {
            if($item['shift_id'] == $shift->id) {
                return $key;
            }
        });
        return $position + 1;
    }

    public function removeMotivationPoint(Order $order)
    {
        $this->where('order_id', $order->id)->delete();
    }
}
