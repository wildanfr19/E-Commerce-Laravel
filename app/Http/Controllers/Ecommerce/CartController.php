<?php

namespace App\Http\Controllers\Ecommerce;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Product;
use App\Province;
use App\City;
use App\District;
use App\Customer;
use App\Order;
use App\OrderDetail;
use Illuminate\Support\Str;
use DB;
use Auth;
use App\Mail\CustomerRegisterMail;
use Mail;
class CartController extends Controller
{

	private function getCarts()
	{
		$carts = json_decode(request()->cookie('dw-carts'), true);
		$carts = $carts != '' ? $carts:[];
		return $carts;
	}

	public function addToCart(Request $request)
	{
		$this->validate($request, [
			'product_id' => 'required|exists:products,id',
			'qty' => 'required|integer'
		]);

		$carts = $this->getCarts();
		if ($carts && array_key_exists($request->product_id, $carts)) {
			$carts[$request->product_id]['qty'] += $request->qty;
		} else {
			$product = Product::find($request->product_id);
			$carts[$request->product_id] = [
				'qty' => $request->qty,
				'product_id' => $product->id,
				'product_name' => $product->name,
				'product_price' => $product->price,
				'product_image' => $product->image,
				'weight' => $product->weight
			];
		}

		$cookie = cookie('dw-carts', json_encode($carts), 2880);
		return redirect()->back()->with(['success' => 'Produk Ditambahkan ke Keranjang'])->cookie($cookie);
	}

	public function listCart()
	{
		$carts = $this->getCarts();
		$subtotal = collect($carts)->sum(function($q){
			return $q['qty'] * $q['product_price'];
		});
		return view('ecommerce.cart', compact('carts', 'subtotal'));
	}

	public function updateCart(Request $request)
	{
		$carts = json_decode(request()->cookie('dw-carts'), true);
		foreach ($request->product_id as $key => $row) {
			if ($request->qty[$key] == 0) {
				unset($carts[$row]);
			} else {
				$carts[$row]['qty'] = $request->qty[$key];
			}

		}

		$cookie = cookie('dw-carts', json_encode($carts), 2800);
		return redirect()->back()->cookie($cookie);
	}


	public function checkout()
	{
		$provinces = Province::orderBy('created_at','DESC')->get();
		$carts = json_decode(request()->cookie('dw-carts'), true);
		$subtotal = collect($carts)->sum(function($q){
			return $q['qty'] * $q['product_price'];
		});

		return view('ecommerce.checkout', compact('provinces', 'carts', 'subtotal'));
	}

	public function getCity()
	{
		$cities = City::where('province_id', request()->province_id)->get();
		return response()->json(['status' => 'success', 'data' => $cities]);
	}
	public function getDistrict()
	{
		$districts = District::where('city_id', request()->city_id)->get();
		return response()->json(['status' => 'success', 'data' => $districts]);
	}

	public function processCheckout(Request $request)
	{
		//Validasi datanya
		$this->validate($request, [
			'customer_name'=> 'required|string|max:100',
			'customer_phone'=> 'required',
			'email' => 'required|email',
			'customer_address'=> 'required|string',
			'province_id'=> 'required|exists:provinces,id',
			'city_id'=> 'required|exists:cities,id',
			'district_id'=> 'required|exists:districts,id' 
		]);
		DB::beginTransaction();
		try {
			// check data customer berdasarkan email
			$customer = Customer::where('email', $request->email)->first();
			if (!auth()->guard('customer')->check() && $cutomer) {
				//MAKA REDIRECT DAN TAMPILKAN INSTRUKSI UNTUK LOGIN 
				return redirect()->back()->with(['error' => 'Silahkan Login Terlebih Dahulu']);
			}
			//Ambil data keranjang
			$carts = $this->getCarts();
			$subtotal = collect($carts)->sum(function($q){
				return $q['qty'] * $q['product_price'];
			});

			// SIMPAN DATA CUSTOMER
			if (!auth()->guard('customer')->check()) {
				$password = Str::random(8);
				$customer = Customer::create([
					'name' => $request->customer_name,
					'email' => $request->email,
					'password' => $password,
					'phone_number' => $request->customer_phone,
					'address'=> $request->customer_address,
					'district_id'=> $request->district_id,
					'activate_token'=> Str::random(30),
					'status'=> false

				]);

			}

			$order = Order::create([
				'invoice' => Str::random(4) . '-' . time(),
				'customer_id' => $customer->id,
				'customer_name' => $customer->name,
				'customer_phone'=> $request->customer_phone,
				'customer_address'=> $request->customer_address,
				'district_id'=> $request->district_id,
				'subtotal'=> $subtotal

			]);

			// looping data di cart
			foreach ($carts as $row) {
				// ambil data product berdasarkan product_id
				$product = Product::find($row['product_id']);
				// SIMPAN DETAIL ORDER
				OrderDetail::create([
					'order_id' => $order->id,
					'product_id' => $row['product_id'],
					'price'=> $row['product_price'],
					'qty'=> $row['product_price'],
					'weight' => $product->weight
				]);
			}
			    //TIDAK TERJADI ERROR, MAKA COMMIT DATANYA UNTUK MENINFORMASIKAN BAHWA DATA SUDAH FIX UNTUK DISIMPAN
			DB::commit();
			
			$carts = [];
			// KOSONGKAN DATA KERANJANG DI COOKIE
			$cookie = cookie('dw-carts', json_encode($carts), 2880);
			if (!auth()->guard('customer')->check()) {
				Mail::to($request->email)->send(new CustomerRegisterMail($customer, $password));
			}
			
			return redirect(route('front.finish_checkout', $order->invoice))->cookie($cookie);

		} catch (Exception $e) {
			DB::rollback();

			//DAN KEMBALI KE FORM TRANSAKSI SERTA MENAMPILKAN ERROR
			return redirect()->back()->with(['error' => $e->getMessage()]);
		}
	}

	public function checkoutFinish($invoice)
	{
	    //AMBIL DATA PESANAN BERDASARKAN INVOICE
		$order = Order::with(['district.city'])->where('invoice', $invoice)->first();
	    //LOAD VIEW checkout_finish.blade.php DAN PASSING DATA ORDER
		return view('ecommerce.checkout_finish', compact('order'));
	}


}
