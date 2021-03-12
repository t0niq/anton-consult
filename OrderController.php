<?php

namespace App\Http\Controllers\Cash;

use App\Http\Controllers\Controller;
use App\Models\Service\OrderBuilder\OrderBuilder;
use Illuminate\Http\Request;
use App\Http\Resources\SohranResource;
use App\Http\Resources\RecomendationResource;
use App\Http\Resources\GuaranteeResource;
use App\Order;
use App\Client;
use App\Shift;
use App\User;
use App\Service;
use App\Promocode;
use App\Car;
use App\Models\Clients101;
use App\KKM\KKMNonFiscal;
use App\Guarantee;

class OrderController extends Controller
{
    public function __construct(KKMNonFiscal $kkmNonFiscal)
    {
        $this->middleware('auth');
        $this->kkmNonFiscal = $kkmNonFiscal;
    }

    public function create(Request $request)
    {
        $order = (new OrderBuilder($request))
            ->setShift()
            ->setStatus()
            ->setClient()
            ->setAdditionalClientPhone()
            ->setPromocode()
            ->setRunflat()
            ->setParams()
            ->setQueue()
            ->saveOrder()
            ->associateWithEmployee()
            ->syncServices()
            ->saveParams()
            ->saveSeasonalStorage()
            ->saveDiskPaint()
            ->getOrder();

        if($request->status == 'safetyreceipt') {
            $template = new SohranResource(Order::find($this->order->id));
            $this->kkmNonFiscal->print($template->jsonSerialize());
        } elseif($request->status == 'recomendation') {
            $template = new RecomendationResource(Order::find($this->order->id));
            $this->kkmNonFiscal->print($template->jsonSerialize());
        }

        return response()->json($order);
    }

    public function update(Request $request) {
        $order = Order::find($request->get('order_id'));

        $services = collect($request->get('cart')['services']);

        $servicesToSync = array();
        //sync services
        //reset synced services - mystical service replacement if weren't dropped
        $order->services()->sync([]);
        $services->each(function ($item, $key) use (&$servicesToSync) {
            $service = Service::find($item['id']);
            array_push($servicesToSync, [
                'service_id' => $service->id,
                'price' => $item['price'],
                'original_price' => $item['original_price'],
                'quantity' => $item['quantity'],
                'manual_price' => $item['manual_set_price']
            ]);
        });
        $order->services()->sync($servicesToSync);

        //save params for services
        $services->each(function ($item, $key) use ($order) {
            if(array_key_exists('params', $item) && !is_null($item['params']) && array_key_exists('diskR', $item['params'])) {
                $order->services()->each(function($serviceRepair) use ($item) {
                    if($serviceRepair->id == $item['id']) {
                        $serviceRepair->pivot->repair()->updateOrCreate(['order_service_id' => $serviceRepair->pivot->id], [
                            'disk_r' => $item['params']['diskR'] ?? '',
                            'disk_type' => $item['params']['diskType'] ?? '',
                            'wheel_side' => $item['params']['wheelSide'] ?? '',
                            'text' => $item['params']['text'] ?? '',
                            'patch' => json_encode($item['params']['patch'] ?? ''),
                            'params' => json_encode($item['params'] ?? '')
                            // 'edgeRestoration' => (json_encode($item['params']['edgeRestoration'] ? 1 : 0) ?? ''),
                            // 'hotVulcan' => (json_encode($item['params']['hotVulcan'] ? 1 : 0)  ?? '')
                            ]);
                    }
                });
            }
            // //Seasonal Storage update
            if(array_key_exists('params', $item) && $item['calc_type'] == 'SeasonalStorageCalc') {
                $order->seasonalStorages()->updateOrCreate([
                    'seal' => $item['params']['seal'],
                    'license_plate' => $item['params']['licensePlate'] ?? $item['params']['license_plate'],
                    'name' => $item['params']['content']['clientName'] ?? $item['params']['name'],
                    'surname' => $item['params']['content']['clientFamily'] ?? $item['params']['surname'],
                    'phone' => $item['params']['content']['clientPhone'] ?? $item['params']['phone'],
                    'storage_period' => $item['params']['content']['duration'] ?? $item['params']['storage_period'],
                    'details' => $item['params']['text'] ?? $item['params']['details']
                    ]);

                // $seasonalStorageTiresCollection = collect($item['params']['content']['tyres']);
                // $seasonalStorageTiresCollection->each(function ($item, $key) use ($order) {
                //     $tire = SeasonalStorageTire::updateOrCreate($item->toArray());
                //     //TODO: remove first() before multiple seasonal storages
                //     $order->seasonalStorages()->first()->tires()->save($tire);
                // });
            }
            // //Disk Paint update
            if(array_key_exists('params', $item) && $item['calc_type'] == 'DiskPaintCalc') {
                $order->diskPaints()->updateOrCreate(['order_id' => $order->id], [
                    'pivot_id' => $order->services->where('id', $item['id'])->where('pivot.price', $item['price'])->first()->pivot->id ?? '',
                    'first_color' => $item['params']['content']['x']['firstColor'],
                    'first_varnish' => $item['params']['content']['x']['firstVarnish'],
                    'second_color' => $item['params']['content']['x']['secondColor'] ?? '',
                    'second_varnish' => $item['params']['content']['x']['secondVarnish'] ?? '',
                    'details' => $item['params']['text']
                    ]);
            }
        });


        $order->status = $request->status == '' ? $order->getOriginal('status') : $request->status;

        if($request->get('promocode')) {
            $promocode = Promocode::where('code', $request->get('promocode')['code'])->first();
            if($promocode) {
                $order->promocode_id = $promocode->id;
                $order->promocode = $promocode->code;
                $order->promocode_discount = $promocode->amount;
            }
        } else {
            $order->resetPromocode();
        }
        
        if(isset($request->get('client')['customer']['phone']) && $request->get('status') != 'safetyreceipt') {

            $user = User::firstOrCreate(
                        ['phone' => $request->get('client')['customer']['phone']],
                        //TODO: can't create order without name
                        ['name' => $request->get('client')['customer']['name'],
                        'surname' => $request->get('client')['customer']['surname'] ?? '',
                        'password' => bcrypt(str_random(10))
                    ]);

            $order->user()->associate($user);

            if($user->wasRecentlyCreated) {
                $newClient = new Client;
                $newClient->save();
                $order->user->userable()->associate($newClient)->save();
            }

            if(!$user->discountCard && $user->wasRecentlyCreated) {
                $client = Clients101::whereJsonContains('customer', ['phone' => $request->get('client')['customer']['phone']])
                ->orWhereJsonContains('discount', ['card_number' => $request->get('card_number')])
                ->first();
                $user->discountCard()
                ->create([
                    'code' => $client->discount['card_number'],
                    'discount' => $client->discount['value'],
                    'additional_discount' => $client->additional_discount['value']
                    ]);

                    $newClient->old_spents = $client->spent;
                    $newClient->save();
            }

            if(isset($request->get('client')['car_id'])) {
                $order->user_car_id = $request->get('client')['car_id'];
            } elseif($request->get('mark') && $request->get('model')) {
                $model = Car::where('mark', $request->get('mark'))->where('model', $request->get('model'))->first();
                $user_car = $order->user->userCars()->firstOrCreate(['car_id' => $model->id, 'user_id' => $order->user->id, 'plate_number' => $request->params['plateNumber']]);
                $order->user_car_id = $user_car->id;
            } else {
                $order->user_car_id = 1;
            }
        }

        $order->save();
        if($request->employee) {
            $order->employee()->associate($request->employee)->save();
        } else {
            $order->employee_id = $order->getOriginal('employee_id');
        }
        $order->sum = $order->sum();

        return response()->json($order);
    }

    public function fetchOrder(Request $request)
    {
        $order = Order::find($request->get('order_id'));
        if($order && $request->load_order) {
            $order->load('services')->services->each(function($item) use ($order) {
                if(!$item->pivot->manual_price) {
                    $item->pivot->price = $item->pivot->original_price;
                }
                $item->params = json_decode($item->pivot->repair->params ?? '');
                if($item->calc_type == "SeasonalStorageCalc") {
                    $item->params =  json_decode($order->seasonalStorages()->first());
                }
            });
            $order->sum = $order->sum();
            return response()->json($order);
        } elseif($order) {
            return response()->json($order->load('services'));
        }
        return abort(500);
    }

    public function createGuaranteeOrder(Request $request)
    {
        $guarantee = new Guarantee;

        $guarantee->shift()->associate(Shift::find($request->get('shift')));
        $guarantee->order()->associate(Order::find($request->get('order_id')));

        $guarantee->save();

        $template = new GuaranteeResource($guarantee);
        $this->kkmNonFiscal->print($template->jsonSerialize());

        return response()->json($guarantee);
    }

    public function setOrderPrevStatus(Request $request)
    {
        $order = Order::findOrFail($request->get('order_id'));
        if($order->status != 'new' || $order->status != 'recomendation' || $order->status != 'safetyreceipt') {
            $statuses = Order::STATUSES;
            while (current($statuses) !== $statuses[$order->status]) next($statuses);
            prev($statuses);
            $order->status = key($statuses);
            $order->save();
            $order->sum = $order->sum();
        }
        return response()->json($order);
    }

}