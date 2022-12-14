<?php

namespace App\Http\Controllers\Payment;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

use App\Models\Customer\Customer;
use App\Models\Inquiry\Inquiry;
use App\Models\Product\Product;
use App\Models\Product\Rsvp as ProductRsvp;
use App\Models\Room\Rsvp as RoomRsvp;
use App\Models\Room\Type;
use App\Models\Payment\Payment;

use Carbon\Carbon;
use DB;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Validator;
use Session;
use \Waavi\Sanitizer\Sanitizer;
use App\Mail\CheckoutEmail;
use Illuminate\Support\Facades\Mail;

class PaymentController extends Controller
{
    public function paymentChannel()
    {
        $merchant          = config('faspay.merchant');
        $merchant_id	   = config('faspay.merchantId');
        $merchant_password = config('faspay.merchantPassword');
        $merchant_user	   = 'bot'.$merchant_id;
        $signature         = sha1(md5($merchant_user.$merchant_password));

        $client = new Client();

        // check url endpoint production or development
        if(config('faspay.endpoint') == true) {
            $url = 'https://web.faspay.co.id/cvr/100001/10';
        } else if (config('faspay.endpoint') == false) {
            $url = 'https://debit-sandbox.faspay.co.id/cvr/100001/10';
        }

        $response = $client->post($url, [
            'json' => [
                'request'     => 'Request List of Payment Gateway',
                'merchant_id' => $merchant_id,
                'merchant'    => $merchant,
                'signature'   => $signature
            ]
        ]);

        return $response->getBody()->getContents();
    }

    public function reserve_room(Request $request)
    {
        $input = $request->all();
        $booking_id = $input['booking_id'];
        $rsvp = RoomRSvp::where('booking_id', $input['booking_id'])->first();
        $data = json_decode($input['data'], true);
        $id_type = ['Identity Card', 'Driver License', 'Passport'];

        if ($data['type'] == "customer") {
            $validator = Validator::make($data, [
                'cust_name' => 'required|regex:/^([a-zA-Z]+)(\s[a-zA-Z]+)*$/|max:50',
                'cust_email' => 'required|email|max:50',
                'cust_phone' => 'required|numeric',
                'guest_name' => 'string',
                'additional_request' => 'string',
            ],
            [
                'cust_name.required' => 'Full Name field is required',
                'cust_name.regex' => 'Full Name field only can contain letter not number',
                'guest_name.string' => 'Guest Name field only can contain letter not number',
                'cust_name.max' => 'Full Name field max only 50 character',
                'cust_email.required' => 'Email field is required',
                'cust_email.email' => 'Email field only can fill with Email',
                'cust_phone.numeric' => 'Phone Number field only can fill with numeric',
                'cust_phone.digits_between' => 'Phone Number field length Maximal 30',
            ]);

            if ($validator->fails()) {
                return response()->json(["status" => 422, "msg" => $validator->messages()->first()]);
            }

            $filters = [
                'cust_name' => 'trim|escape|capitalize',
                'cust_email' => 'trim|escape|lowercase',
                'guest_name' => 'trim|escape|capitalize',
                'cust_phone' => 'digit',
                'cust_id_num' => 'digit',
                'additional_request' => 'strip_tags',
            ];

            $sanitizer = new Sanitizer($data, $filters);
            $sanitizer = $sanitizer->sanitize();
            $cust_email = $sanitizer['cust_email'];

            if (Customer::where('cust_email', $cust_email)->exists()) {
                $customer_id = Customer::where('cust_email', $cust_email)->pluck('id')->first();
            } else {
                $bytes = openssl_random_pseudo_bytes(4, $cstrong);
                $hex = bin2hex($bytes);
                $customer_id = $hex;
                while (Customer::where('id', $customer_id)->exists()) {
                    $bytes = openssl_random_pseudo_bytes(4, $cstrong);
                    $hex = bin2hex($bytes);
                    $customer_id = $hex;
                }

                $customer = [
                    'id' => $customer_id,
                    'cust_email' => $cust_email,
                ];

                Customer::create($customer);
            }

            $rsvp = RoomRsvp::where('booking_id', $booking_id)->orderBy('rsvp_date_reserve', 'ASC')->first();
            $checkIn = $rsvp->rsvp_date_reserve;

            $getRoom = Type::where('id', $rsvp->room_id)->first();

            RoomRsvp::where('booking_id', $booking_id)->update([
                'customer_id' => $customer_id,
                'rsvp_cust_name' => $sanitizer['cust_name'],
                'rsvp_cust_phone' => $sanitizer['cust_phone'],
                'rsvp_guest_name' => $sanitizer['guest_name'],
                'rsvp_special_request' => $sanitizer['additional_request'],
            ]);

            return response()->json(["status" => 200, "href" => "tab2-2", "customer_name" => $sanitizer['cust_name'], "customer_email" => $sanitizer['cust_email'], "booking_id" => $input['booking_id'], "tab" => "2", "text" => "Payment Information"]);

        } else {
            return response()->json(["status" => 422, "msg" => "Something went wrong"]);
        }
    }

    public function room_checkout(Request $request)
    {
        $input               = $request->all();
        $data                = $input['data'];
        $booking_id          = $input['booking_id'];
        $payment_channel     = $input['payment_channel'];
        $bill_total          = $data['total_price'].'00';
        $total_price         = $data['total_price'];

        $booking             = RoomRSvp::where('booking_id', $input['booking_id'])->first();
        $email               = Customer::where('id', $booking->customer_id)->first();

        // user
        $merchant	         = config('faspay.merchant');
        $merchant_id	     = config('faspay.merchantId');
        $merchant_password   = config('faspay.merchantPassword');
        $merchant_user	     = 'bot'.$merchant_id;

        // search payment channel
        $paymentChannels     = $this->paymentChannel();
        $listPaymentChannels = json_decode($paymentChannels, true);
        $name_payment        = $payment_channel;
        $key                 = array_search($name_payment, array_column($listPaymentChannels['payment_channel'], 'pg_code'));
        $result              = $listPaymentChannels['payment_channel'][$key]['pg_name'];

        $bill_no	         = $booking->booking_id;
        $request             = 'Room Reservation of '.$bill_no;
        $cust_no             = $booking->customer_id;
        $cust_name           = $booking->rsvp_cust_name;
        $msisdn              = '+'.$booking->rsvp_cust_phone;
        $bill_date           = Carbon::now();
        // $bill_expired        = Carbon::now()->addHours(1);
        $bill_expired        = Carbon::now()->addMinutes(20);
        $bill_desc           = 'Room Reservation of '.$bill_no;
        $signature	         = sha1(md5($merchant_user.$merchant_password.$bill_no));

        $client = new Client();

        // check url endpoint production or development
        if(config('faspay.endpoint') == true) {
            $url = 'https://web.faspay.co.id/cvr/300011/10';
        } else if (config('faspay.endpoint') == false) {
            $url = 'https://debit-sandbox.faspay.co.id/cvr/300011/10';
        }

        // rooms
        $item1_details = array(
            'product'      => $data['total_rooms'] . "x " . $data['room_name'] . " x " . $data['total_days'] . " day(s)",
            'qty'          => $data['total_rooms'],
            'amount'       => $data['total_room_price'],
            'payment_plan' => '01',
            'merchant_id'  => $merchant_id,
            'tenor'        => '00'
        );

        // extrabed
        $item2_details = array(
            'product'      => $data['total_extrabed'] . "x " ."Additional Extra Bed". " x " . $data['total_days'] . " day(s)",
            'qty'          => $data['total_extrabed'],
            'amount'       => $data['total_extrabed_price'],
            'payment_plan' => '01',
            'merchant_id'  => $merchant_id,
            'tenor'        => '00'
        );

        // cek extrabed
        if ($data['total_extrabed_price'] == NULL) {
            $item_details = array($item1_details);
        } else {
            $item_details = array($item1_details, $item2_details);
        }

        $response = $client->post($url, [
            'json' => [
                'request'          => $request,
                'merchant_id'      => $merchant_id,
                'merchant'         => $merchant,
                'bill_no'          => $bill_no,
                'bill_date'        => $bill_date,
                'bill_expired'     => $bill_expired,
                'bill_desc'        => $bill_desc,
                'bill_currency'    => 'IDR',
                'bill_total'       => $bill_total,
                'bill_gross'       => $bill_total,
                'payment_channel'  => $payment_channel,
                'pay_type'         => '1',
                'cust_no'          => $cust_no,
                'cust_name'        => $cust_name,
                'msisdn'           => $msisdn,
                'email'            => $email->cust_email,
                'terminal'         => '10',
                'item'             => $item_details,
                'reserve1'         => 'ROOMS',
                'signature'        => $signature
            ]
        ]);

        // return $response->getBody()->getContents();

        $data           = json_decode($response->getBody()->getContents(), true);
        $transaction_id = $data['trx_id'];
        $redirect_url   = $data['redirect_url'];

        Payment::create([
            'transaction_id'     => $data['trx_id'],
            'booking_id'         => $data['bill_no'],
            'merchant_id'        => $data['merchant_id'],
            'from_table'         => 'ROOMS',
            'gross_amount'       => $total_price,
            'currency'           => 'IDR',
            'transaction_status' => 'pending',
            'transaction_time'   => $bill_date,
            // 'fraud_status'       => $data['response_desc'],
            'payment_type'       => $result,
            'status_code'        => $data['response_code'],
            'status_message'     => $data['response'],
            'signature_key'      => $signature,
            'redirect_url'       => $data['redirect_url']
        ]);

        RoomRsvp::where('booking_id', $booking_id)->update([
            'rsvp_payment' => $result,
            'expired_at'   => $bill_expired
        ]);

        // Email Checkout Confirmation
        $setting       = $this->setting();
        $data          = RoomRSvp::where('booking_id', $input['booking_id'])->first();
        $data->subject = 'Booking - '.$data->booking_id;
        $payment       = Payment ::where('booking_id', $input['booking_id'])->first();

        Mail::to($email->cust_email)->send(new CheckoutEmail($data, $payment, $setting));

        return response()->json(["status" => 200, "transaction_id" => $transaction_id, "payment_type" => $result, "bill_expired" => $bill_expired, "redirect_url" => $redirect_url, "href" => "tab2-3"]);
    }

    public function reserve_product(Request $request)
    {
        $input = $request->all();
        $booking_id = $input['booking_id'];
        $rsvp = ProductRsvp::where('booking_id', $input['booking_id'])->first();

        $data = json_decode($input['data'], true);
        $id_type = ['Identity Card', 'Driver License', 'Passport'];
        $productData = Product::where('id', $data['product_id'])->first();

        $data['time_reserve'] = $data['date_reserve'] . ' ' . $data['time_reserve'];
        $data['time_reserve'] = Carbon::parse($data['time_reserve'])->isoFormat('YYYY-MM-DD HH:mm');

        $validator = Validator::make($data, [
            'cust_name' => 'required|regex:/^([a-zA-Z]+)(\s[a-zA-Z]+)*$/|max:50',
            'cust_email' => 'required|email|max:50',
            'cust_phone' => 'required|numeric',
            'product_id' => 'required|exists:product,id',
            'amount_pax' => 'required|numeric|min:1|max:4',
            'date_reserve' => 'required|after_or_equal:today',
            'time_reserve' => 'required|date_format:Y-m-d H:i|after:now',
            'additional_request' => 'string',
        ],
        [
            'cust_name.required' => 'Full Name field is required',
            'cust_name.regex' => 'Full Name field only can contain letter not number',
            'guest_name.string' => 'Guest Name field only can contain string',
            'cust_name.max' => 'Full Name field max only 50 character',
            'cust_email.required' => 'Email field is required',
            'cust_email.email' => 'Email field only can fill with Email',
            'cust_phone.numeric' => 'Phone Number field only can fill with numeric',
            'cust_phone.digits_between' => 'Phone Number field length Maximal 30',
            'product_id.required' => 'Product is required',
            'product_id.exists' => 'Product is not found',
            'amount_pax.required' => 'Amount Pax field is required',
            'amount_pax.numeric' => 'Amount Pax field is only can contain numeric',
            'amount_pax.min' => 'Amount Pax field minimal 1 Pax',
            'amount_pax.max' => 'Amount Pax field maximal 4 Pax',
            'date_reserve.required' => 'Product Reservation Date field is required',
            'date_reserve.after_or_equal' => 'Product Reservation Date field date cannot less than today',
            'time_reserve.after' => 'Please set your reservation arrival time higher than the current time',
        ]);

        if ($validator->fails()) {
            return response()->json(["status" => 422, "msg" => $validator->messages()->first()]);
        }

        $data['time_reserve'] = Carbon::parse($data['time_reserve'])->isoFormat('h:mm A');
        if ($data['type'] == "customer") {

            $filters = [
                'cust_name' => 'trim|escape|capitalize',
                'cust_email' => 'trim|escape|lowercase',
                'payment_type' => 'trim|escape|capitalize',
                'cust_phone' => 'digit',
                'cust_id_num' => 'digit',
                'amount_pax' => 'digit',
                'date_reserve' => 'trim|format_date:d M Y, Y-m-d',
                'payment_number' => 'digit',
                'additional_request' => 'strip_tags',

            ];

            $sanitizer = new Sanitizer($data, $filters);

            $sanitizer = $sanitizer->sanitize();

            $booking_id = $input['booking_id'];

            $rsvp_pax_price = $productData->product_price;

            $rsvp_amount_pax = $sanitizer['amount_pax'];
            $rsvp_total_amount = $rsvp_pax_price * $rsvp_amount_pax;

            $rsvp_service = floor($rsvp_total_amount * 0.1);
            $rsvp_tax = floor(($rsvp_total_amount + $rsvp_service) * 0.1);
            $rsvp_total_amount -= $rsvp_service + $rsvp_tax;

            $rsvp_pax_price = floor($rsvp_total_amount / $rsvp_amount_pax);

            $grandTotal = $rsvp_total_amount + $rsvp_tax + $rsvp_service;

            $cust_email = $sanitizer['cust_email'];

            if (Customer::where('cust_email', $cust_email)->exists()) {

                $customer_id = Customer::where('cust_email', $cust_email)->pluck('id')->first();
            } else {
                $bytes = openssl_random_pseudo_bytes(4, $cstrong);
                $hex = bin2hex($bytes);
                $customer_id = $hex;
                while (Customer::where('id', $customer_id)->exists()) {
                    $bytes = openssl_random_pseudo_bytes(4, $cstrong);
                    $hex = bin2hex($bytes);
                    $customer_id = $hex;
                }

                $customer = [
                    'id' => $customer_id,
                    'cust_email' => $cust_email,
                ];

                Customer::create($customer);
            }

            ProductRsvp::where('booking_id', $booking_id)->update([
                'customer_id' => $customer_id,
                'rsvp_date_reserve' => $sanitizer['date_reserve'],
                'rsvp_arrive_time' => $data['time_reserve'],
                'rsvp_cust_name' => $sanitizer['cust_name'],
                'rsvp_cust_phone' => $sanitizer['cust_phone'],
                'rsvp_special_request' => $sanitizer['additional_request'],
                'rsvp_amount_pax' => $rsvp_amount_pax,
                'rsvp_pax_price' => $rsvp_pax_price,
                'rsvp_total_amount' => $rsvp_total_amount,
                'rsvp_tax' => $rsvp_tax,
                'rsvp_service' => $rsvp_service,
                'rsvp_tax_total' => ($rsvp_tax + $rsvp_service),
                'rsvp_grand_total' => $grandTotal,
            ]);

            return response()->json(["status" => 200, "href" => "tab2-2", "customer_name" => $sanitizer['cust_name'], "customer_email" => $sanitizer['cust_email'], "tab" => "2", "text" => "Payment Information"]);

        } elseif ($data['type'] == "credit" || $data['type'] == "bank") {
            return response()->json(["status" => 200, "msg" => $data['type'], "transaction" => $transaction]);
        }
    }

    public function product_checkout(Request $request)
    {
        $input               = $request->all();
        $data                = $input['data'];
        $booking_id          = $input['booking_id'];
        $payment_channel     = $input['payment_channel'];
        $bill_total          = $data['total_price'].'00';

        $booking             = ProductRSvp::where('booking_id', $input['booking_id'])->first();
        $email               = Customer   ::where('id', $booking->customer_id)->first();

        // user
        $merchant	         = config('faspay.merchant');
        $merchant_id	     = config('faspay.merchantId');
        $merchant_password   = config('faspay.merchantPassword');
        $merchant_user	     = 'bot'.$merchant_id;

        // search payment channel
        $paymentChannels     = $this->paymentChannel();
        $listPaymentChannels = json_decode($paymentChannels, true);
        $name_payment        = $payment_channel;
        $key                 = array_search($name_payment, array_column($listPaymentChannels['payment_channel'], 'pg_code'));
        $result              = $listPaymentChannels['payment_channel'][$key]['pg_name'];

        $bill_no	         = $booking->booking_id;
        $request             = 'Product Reservation of '.$bill_no;
        $cust_no             = $booking->customer_id;
        $cust_name           = $booking->rsvp_cust_name;
        $msisdn              = '+'.$booking->rsvp_cust_phone;
        $bill_date           = Carbon::now();
        // $bill_expired        = Carbon::now()->addHours(1);
        $bill_expired        = Carbon::now()->addMinutes(20);
        $bill_desc           = 'Product Reservation of '.$bill_no;
        $signature	         = sha1(md5($merchant_user.$merchant_password.$bill_no));

        $client = new Client();

        // check url endpoint production or development
        if(config('faspay.endpoint') == true) {
            $url = 'https://web.faspay.co.id/cvr/300011/10';
        } else if (config('faspay.endpoint') == false) {
            $url = 'https://debit-sandbox.faspay.co.id/cvr/300011/10';
        }

        $response = $client->post($url, [
            'json' => [
                'request'          => $request,
                'merchant_id'      => $merchant_id,
                'merchant'         => $merchant,
                'bill_no'          => $bill_no,
                'bill_date'        => $bill_date,
                'bill_expired'     => $bill_expired,
                'bill_desc'        => $bill_desc,
                'bill_currency'    => 'IDR',
                'bill_total'       => $bill_total,
                'bill_gross'       => $bill_total,
                'payment_channel'  => $payment_channel,
                'pay_type'         => '1',
                'cust_no'          => $cust_no,
                'cust_name'        => $cust_name,
                'msisdn'           => $msisdn,
                'email'            => $email->cust_email,
                'terminal'         => '10',
                'item'             => [
                    'product'      => $data['amount_pax'] . " x " . $data['product_name'],
                    'qty'          => $data['amount_pax'],
                    'amount'       => $bill_total,
                    'payment_plan' => '01',
                    'merchant_id'  => $merchant_id,
                    'tenor'        => '00'
                ],
                'reserve1'         => 'PRODUCTS',
                'signature'        => $signature
            ]
        ]);

        // return $response->getBody()->getContents();

        $data           = json_decode($response->getBody()->getContents(), true);
        $transaction_id = $data['trx_id'];
        $product_total  = $booking->rsvp_grand_total;
        $redirect_url   = $data['redirect_url'];

        Payment::create([
            'transaction_id'     => $data['trx_id'],
            'merchant_id'        => $data['merchant_id'],
            'booking_id'         => $data['bill_no'],
            'from_table'         => 'PRODUCTS',
            'gross_amount'       => $booking->rsvp_grand_total,
            'currency'           => 'IDR',
            'transaction_status' => 'pending',
            'transaction_time'   => $bill_date,
            // 'fraud_status'       => $data['response_desc'],
            'payment_type'       => $result,
            'status_code'        => $data['response_code'],
            'status_message'     => $data['response'],
            'signature_key'      => $signature,
            'redirect_url'       => $data['redirect_url']
        ]);

        ProductRsvp::where('booking_id', $booking_id)->update([
            'rsvp_payment' => $result,
            'expired_at'   => $bill_expired
        ]);

        // Email Checkout Confirmation
        $setting       = $this->setting();
        $data          = ProductRSvp::where('booking_id', $input['booking_id'])->first();
        $data->subject = 'Booking - '.$data->booking_id;
        $payment       = Payment    ::where('booking_id', $input['booking_id'])->first();

        Mail::to($email->cust_email)->send(new CheckoutEmail($data, $payment, $setting));

        return response()->json(["status" => 200, "transaction_id" => $transaction_id, "bill_expired" => $bill_expired, "product_total" => $product_total, "payment_type" => $result, "redirect_url" => $redirect_url, "href" => "tab2-3"]);
    }

    public function credit(Request $request)
    {
        $data        = $request['reserve_data'];
        $data        = json_decode($data);

        $data_amount = $data->total_price;
        $booking_id  = $data->booking_id;
        $amount      = number_format( (float) $data_amount, 2, '.', '');
        $from        = $data->from;

        // insert payment
        $merchant_id = config('faspay.merchantIdCredit');
        $password    = config('faspay.merchantPasswordCredit');
        $tranid      = $booking_id;

        $signaturecc = sha1('##'.strtoupper($merchant_id).'##'.strtoupper($password).'##'.$tranid.'##'.$amount.'##'.'0'.'##');

        if ($from == "ROOMS") {
            $booking      = RoomRSvp::where('booking_id', $booking_id)->first();
            $bill_date    = $booking->created_at;
            $bill_expired = $booking->expired_at;
            $from         = 'ROOMS';

            //order description room
            $room_desc   = $data->total_rooms . "x " . $data->room_name . " x " . $data->total_days . " day(s)";

            //order description room plus extrabed
            $extrabed_desc   = $data->total_rooms . "x " . $data->room_name . " x " . $data->total_days . " day(s) | " . $data->total_extrabed . "x " . "Additional Extra Bed" . " x " . $data->total_days . " day(s)";

            // cek extrabed
            if ($data->total_extrabed_price == "0") {
                $order_desc = $room_desc;
            } else {
                $order_desc = $extrabed_desc;
            }

            // customer
            $customer     = Customer::where('id', $booking->customer_id)->first();
            $email        = $customer->cust_email;
            $name         = $booking->rsvp_cust_name;
            $phone        = $booking->rsvp_cust_phone;
        } else {
            $booking      = ProductRSvp::where('booking_id', $booking_id)->first();
            $bill_date    = $booking->created_at;
            $bill_expired = $booking->expired_at;
            $from         = 'PRODUCTS';

            //order description
            $order_desc   = $data->total_pax . " x " . $data->product_name;

            // customer
            $customer     = Customer::where('id', $booking->customer_id)->first();
            $email        = $customer->cust_email;
            $name         = $booking->rsvp_cust_name;
            $phone        = $booking->rsvp_cust_phone;
        }

        Payment::create([
            'booking_id'         => $booking_id,
            'merchant_id'        => $merchant_id,
            'from_table'         => $from,
            'gross_amount'       => $amount,
            'currency'           => 'IDR',
            'transaction_status' => 'pending',
            'transaction_time'   => $bill_date,
            'payment_type'       => 'Credit Card',
            'signature_key'      => $signaturecc,
        ]);

        // assets url and image
        $url_credit    = route('credit.notification');
        $setting       = $this->setting();
        $img           = asset('images/logo/');
        $assets_credit = $img."/".$setting->logo;

        if(config('faspay.endpoint') == true) {
            $endpoint = 'https://fpg.faspay.co.id/payment';
        } else if (config('faspay.endpoint') == false) {
            $endpoint = 'https://fpg-sandbox.faspay.co.id/payment';
        }

        $string = '<form method="post" name="form" action="'.$endpoint.'">';
        $post = array(
            "TRANSACTIONTYPE"               => '1',
            "RESPONSE_TYPE"	                => '2',
            "MERCHANTID"                    => $merchant_id,
            "PAYMENT_METHOD"                => '1',
            "TXN_PASSWORD" 	                => $password,
            "MERCHANT_TRANID"               => $tranid,
            "CURRENCYCODE"	                => 'IDR',
            "AMOUNT"		                => $amount,
            "CUSTNAME"                      => $name,
            "CUSTEMAIL"		                => $email,
            "DESCRIPTION"                   => $order_desc,
            "RETURN_URL"                    => $url_credit,
            "SIGNATURE" 	                => $signaturecc,
            // "BILLING_ADDRESS"				=> 'bekasi',
            // "BILLING_ADDRESS_CITY"			=> 'bekasi',
            // "BILLING_ADDRESS_REGION"		=> 'bekasi',
            // "BILLING_ADDRESS_STATE"			=> 'bekasi pusat6',
            // "BILLING_ADDRESS_POSCODE"		=> '10712',
            // "BILLING_ADDRESS_COUNTRY_CODE"	=> 'ID',
            // "SHIPPING_ADDRESS" 				=> 'bekasi air enam',
            // "SHIPPING_ADDRESS_CITY" 		=> 'bekasi tengah',
            // "SHIPPING_ADDRESS_REGION"		=> 'bekasi tengah',
            // "SHIPPING_ADDRESS_STATE"		=> 'bekasi tengah',
            // "SHIPPING_ADDRESS_POSCODE"		=> 'bekasi tengah',
            // "SHIPPING_ADDRESS_COUNTRY_CODE" => 'bekasi tengah',
            "PHONE_NO" 						=> '+'.$phone,
            "style_merchant_name"           => 'black',
            "style_order_summary"           => 'black',
            "style_order_no"                => 'black',
            "style_order_desc"              => 'black',
            "style_amount"                  => 'black',
            "style_background_left"         => '#fff',
            "style_button_cancel"           => 'grey',
            "style_font_cancel"             => 'red',
            //harus url yg lgsg ke gambar
            "style_image_url"               => $assets_credit,
            );

            // $string = '<form method="post" name="form" action="https://fpgdev.faspay.co.id/payment">';  // yang diubah URLnya ke prod apa dev
            if ($post != null) {
                foreach ($post as $name=>$value) {
                    $string .= '<input type="hidden" name="'.$name.'" value="'.$value.'">';
                }
            }

        $string .= '</form>';
        $string .= '<script> document.form.submit();</script>';

        echo $string;
        exit;
    }

    public function generateSignature($merchant_user,$merchant_password,$bill_no,$bill_total)
    {
        return sha1(md5($merchant_user.$merchant_password.$bill_no.$bill_total));
    }

    // uses regex that accepts any word character or hyphen in last name
    public function split_name($name)
    {
        $name = trim($name);
        $last_name = (strpos($name, ' ') === false) ? '' : preg_replace('#.*\s([\w-]*)$#', '$1', $name);
        $first_name = trim(preg_replace('#' . $last_name . '#', '', $name));
        return array($first_name, $last_name);
    }
}
