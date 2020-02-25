<?php
namespace Laravel\Cashier\AuthorizeNet;

use Exception;
use Carbon\Carbon;
use LogicException;
use DateTimeInterface;
use Illuminate\Support\Facades\Config;
use Illuminate\Database\Eloquent\Model;
use Laravel\CashierAuthorizeNet\Requester;

use net\authorize\api\contract\v1\ARBCancelSubscriptionRequest,
	net\authorize\api\controller\ARBCancelSubscriptionController;

use App\Account;

class Subscription extends Model
{
	/**
	 * The attributes that aren't mass assignable.
	 *
	 * @var array
	 */
	protected $guarded = [];

	/**
	 * The attributes that should be mutated to dates.
	 *
	 * @var array
	 */
	protected $dates = [
		'trial_ends_at', 'ends_at',
		'created_at', 'updated_at',
	];

	/**
	 * Get the user that owns the subscription.
	 */
	public function user()
	{
		$model = getenv('ADN_MODEL')?? config('services.authorize.model', Account::class );
		return $this->belongsTo($model, 'account_id');
	}

	/**
	 * Determine if the subscription is active, on trial, or within its grace period.
	 *
	 * @return bool
	 */
	public function valid()
	{
		return $this->active() || $this->onTrial() || $this->onGracePeriod();
	}

	/**
	 * Determine if the subscription is active.
	 *
	 * @return bool
	 */
	public function active()
	{
		return is_null($this->ends_at) || $this->onGracePeriod();
	}

	/**
	 * Determine if the subscription is no longer active.
	 *
	 * @return bool
	 */
	public function cancelled()
	{
		return ! is_null($this->ends_at);
	}

	/**
	 * Determine if the subscription is within its trial period.
	 *
	 * @return bool
	 */
	public function onTrial()
	{
		if (! is_null($this->trial_ends_at)) {
			return Carbon::today()->lt($this->trial_ends_at);
		} else {
			return false;
		}
	}

	/**
	 * Determine if the subscription is within its grace period after cancellation.
	 *
	 * @return bool
	 */
	public function onGracePeriod()
	{
		if (! is_null($endsAt = $this->ends_at)) {
			return Carbon::now()->lt(Carbon::instance($endsAt));
		} else {
			return false;
		}
	}

	/**
	 * Cancel the subscription at the end of the billing period.
	 *
	 * @return $this
	 */
	public function cancel()
	{
		$instance = new Requester;
		$today = Carbon::now('America/Denver');
		
		$authorize_id = $this->authorize_id;
		
		$billingDay = $this->created_at->day;
		$billingDate = Carbon::createFromDate($today->year, 
											  $today->month, 
											  $billingDay)
			->timezone('America/Denver');
		
		$endingDate = $today->gte($endingDate)? 
			$billingDate->addDays($this->getBillingDays()): 
			$billingDate;
		
		$request = $instance->prepare(new ARBCancelSubscriptionRequest);
		$request->setSubscriptionId($authorize_id);
		
		$controller = new ARBCancelSubscriptionController($request);
		$response = $controller->executeWithApiResponse($instance->env);
		
		$message = $response->getMessages();
		
		if ($response && strcmp($message->getResultCode(), "Ok") === 0) 
		{
			// If the user was on trial, we will set the grace period to end when the trial
			// would have ended. Otherwise, we'll retrieve the end of the billing period
			// period and make that the end of the grace period for this current user.
			$this->ends_at = $this->onTrial()? 
				$this->trial_ends_at: $endingDate;
			
			$this->save();
		}
		else {
			$excMesg = $message->getMessage();
			
			throw new Exception(sprintf(
				'Payment Processor: %d: %s', $excMesg->getCode(), $excMesg->getMessage()), 1);
		}

		return $this;
	}

	/**
	 * Cancel the subscription immediately.
	 *
	 * @return $this
	 */
	public function cancelNow()
	{
		$this->cancel();
		$this->markAsCancelled();

		return $this;
	}

	/**
	 * Mark the subscription as cancelled.
	 *
	 * @return void
	 */
	public function markAsCancelled()
	{
		$model = $this->fill([ 'ends_at' => Carbon::now() ]);
		$model->save();
	}

	/**
	 * Get billing days
	 *
	 * @return integer
	 */
	public function getBillingDays()
	{
		$config = Config::get('cashier-authorize');
		$authorize_plan = array_get($config, $this->authorize_plan);
		
		switch (array_get($authorize_plan, 'interval.unit')) {
			case 'months': $days = 31;	break;
			case 'days':   $days = 1;	break;
			case 'weeks':  $days = 7;	break;
			case 'years':  $days = 365;	break;
		}
		
		return $days * array_get($authorize_plan, 'interval.length');
	}
}
