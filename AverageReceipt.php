<?php

namespace App\Models\Service\AverageReceipt;

use App\Service;
use Illuminate\Database\Eloquent\Model;

class AverageReceipt extends Model
{
    protected $guarded = [];

    protected $table = "average_receipts";

    public function path()
    {
        return "/backoffice/averagereceipts/{$this->id}";
    }

    public function services()
    {
        return $this->belongsToMany(Service::class)
            ->withPivot('id', 'addSumWhenMany', 'addSumWhenSingle', 'addCountWhenMany', 'addCountWhenSingle');
    }

    public function hoster()
    {
        return $this->morphTo();
    }

    public function getAverageReceipt()
    {
        return $this->getSum($this->orders->paid()) / $this->getCount($this->orders->paid());
    }

    public static function create(...$params)
    {
        return new static(...$params);
    }

    private function getSum($orders)
    {
        $sum = 0;
        $orders->each(function ($order) use (&$sum) {
            $sum += $order->sum();
        });
        return $sum;
    }

    private function getCount($orders)
    {
        $count = 0;
        $orders->each(function ($order) use (&$count) {
            $count += 1;
        });
        return $count;
    }

    public function addSumWhenSingle(Service $service): bool
    {
        if ($this->services->contains($service) && $this->services->find($service)->pivot->addSumWhenSingle) {
            return true;
        }
        return false;
    }

    public function addCountWhenSingle(Service $service): bool
    {
        if ($this->services->contains($service) && $this->services->find($service)->pivot->addCountWhenSingle) {
            return true;
        }
        return false;
    }

    public function addSumWhenMany(Service $service): bool
    {
        if ($this->services->contains($service) && $this->services->find($service)->pivot->addSumWhenMany) {
            return true;
        }
        return false;
    }

    public function addCountWhenMany(Service $service): bool
    {
        if ($this->services->contains($service) && $this->services->find($service)->pivot->addCountWhenMany) {
            return true;
        }
        return false;
    }

    public function updateServicesSettings(array $services)
    {
        foreach ($services as $id => $params) {
            $this->updateServiceParams($id, $params);
        }
    }

    private function updateServiceParams($id, $params)
    {
        $service = $this->services->find($id);
        isset($params['addSumWhenMany']) ?
            $this->services()->updateExistingPivot($service, array('addSumWhenMany' => 1), false) :
            $this->services()->updateExistingPivot($service, array('addSumWhenMany' => 0), false);
        isset($params['addSumWhenSingle']) ?
            $this->services()->updateExistingPivot($service, array('addSumWhenSingle' => 1), false) :
            $this->services()->updateExistingPivot($service, array('addSumWhenSingle' => 0), false);
        isset($params['addCountWhenMany']) ?
            $this->services()->updateExistingPivot($service, array('addCountWhenMany' => 1), false) :
            $this->services()->updateExistingPivot($service, array('addCountWhenMany' => 0), false);
        isset($params['addCountWhenSingle']) ?
            $this->services()->updateExistingPivot($service, array('addCountWhenSingle' => 1), false) :
            $this->services()->updateExistingPivot($service, array('addCountWhenSingle' => 0), false);
    }
}
