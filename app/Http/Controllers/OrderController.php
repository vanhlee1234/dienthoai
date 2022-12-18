<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Order_item;
use App\Models\Order;
use App\Models\Customer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use App\Models\Province;
use App\Models\District;
use App\Models\Ward;
use App\Models\Category;
use App\Models\Brand;
use App\Models\Product;
use App\Models\seesions;
use Illuminate\Support\Facades\DB;
use Gloudemans\Shoppingcart\Facades\Cart;
use Illuminate\Support\Facades\Mail;

class OrderController extends Controller
{
    const STATUS_INACTIVE = 0;
    const STATUS_ACTIVE = 1;
    const STATUS_DELETED = 2;
    const PER_PAGE = 10;
    const PER_PAGE_FRONT = 12;
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {

        $inputName = "";
        $inputPhone = "";
        $inputEmail = "";
        $query = "";
        $orders = [];

        if (isset($request->inputName)) {
            $inputName = $request->inputName;
            if ($query == "") {
                $query .= " " . "customer_name like '%" . $request->inputName . "%'";
            } else {
                $query .= " AND " . "customer_name like '%" . $request->inputName . "%'";
            }
        }

        if (isset($request->inputPhone)) {
            $inputPhone = $request->inputPhone;
            if ($query == "") {
                $query .= " " . "customer_phone like '%" . $request->inputPhone . "%'";
            } else {
                $query .= " AND " . "customer_phone like '%" . $request->inputPhone . "%'";
            }
        }

        if (isset($request->inputEmail)) {
            $inputEmail = $request->inputEmail;
            if ($query == "") {
                $query .= " " . "customer_email like '%" . $request->inputEmail . "%'";
            } else {
                $query .= " AND " . "customer_email like '%" . $request->inputEmail . "%'";
            }
        }

        if (isset($request->btnSearch)) {
            if (!isset($request->inputName) && !isset($request->inputPhone) && !isset($request->inputEmail)) {
                $orders = Order::orderBy('id', 'DESC')->get();
            } else {
                $orders = DB::select('SELECT * FROM orders WhERE ' . $query);
            }
        } else {
            $orders = Order::all();
        }

        $categories = Category::where('active', self::STATUS_ACTIVE)->orderBy('sort_order', 'ASC')->get();
        $brands = Brand::where('active', self::STATUS_ACTIVE)->orderBy('sort_order', 'ASC')->get();
        $itemOrder = Order_item::all();

        return view('admin/order/show', compact('categories', 'brands', 'orders', 'itemOrder'));
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        $totalMoney = 0;
        $quantity = 0;
        $carts = Cart::content();

        $products  = Product::where('active', self::STATUS_ACTIVE)->limit(self::STATUS_DELETED + 1)->get();
        $provinces = Province::all();
        $productBestSell = Product::where('active', self::STATUS_ACTIVE)->where('is_best_sell', '=', self::STATUS_ACTIVE)->orderBy('id', 'DESC')->paginate(self::STATUS_DELETED);

        foreach ($carts as $keyCart => $cartData) {
            $totalMoney +=  $cartData->price * $cartData->qty;
            $quantity += $cartData->qty;
        }

        if ($carts->isEmpty()) {
            session()->flash('messageCartEmpty', 'Cart is empty!');
            return view('web/cart/show', compact('carts', 'products', 'productBestSell'))->with('totalMoney', $totalMoney)->with('quantity', $quantity);
        } else {
            return view('web/order/add', compact('carts', 'products', 'productBestSell', 'provinces'))->with('quantity', $quantity)->with('totalMoney', $totalMoney);
        }    
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        Mail::send('/web/orderSucess', ['customerName' => $request->customer_name, 'totalMoney' => $request->total_money,], function ($message) {
            $message->to('bonbon2k1a@gmail.com')->subject('Order');
        }, 'Bạn đã mua sản phẩm tại Shop');

        $tinh = Province::find($request->provinces);
        $huyen = District::find($request->districts);
        $xa = Ward::find($request->wards);
        $address = $request->address;
        $diachi = $address . '-' . $xa->name . '-' . $huyen->name . '-' . $tinh->name;
        $today = Carbon::today();

        $cart = Cart::content();

        $dataOrder = [
            'user_id' => $request->user_id,
            'customer_name' => $request->customer_name,
            'customer_phone' => $request->customer_phone,
            'customer_email' => $request->customer_email,
            'total_money' => $request->total_money,
            'total_products' => $request->total_products,
            'created_date' => $today,
            'address' => $diachi,
            'status' => "0"
        ];

        $order = Order::create($dataOrder);
        if (isset($order)) {

            if (isset($request->user_id)) {
                $customer = Customer::find($request->user_id);
                $customer->name = $request->customer_name;
                $customer->phone = $request->customer_phone;
                $customer->email = $request->customer_email;
                $customer->save();
            }

            foreach ($cart as $cartItem) {
                $data = [
                    'order_id' => $order->id,
                    'product_id' => $cartItem->id,
                    'product_name' => $cartItem->name,
                    'product_image' => $cartItem->options->image,
                    'product_price' => $cartItem->price,
                    'product_quantity' => $cartItem->qty
                ];
                $orderId = Order_item::create($data);
            }
            Cart::destroy();
        }

        return redirect()->route('orderSuccess');
    }

    public function orderSuccess() {

        $idOrder = 0;

        if (isset(Auth::user()->id)) {
            $order = DB::select("SELECT MAX(id) as idOrder FROM orders WHERE customer_email like '%".Auth::user()->email."%'");
            foreach ($order as $order) {
                $idOrder = $order->idOrder;
            }
        }

        return view('/web/orderSucess')->with('idOrder', $idOrder);
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($email)
    {
        $totalMoney = 0;
        $quantity = 0;

        $products  = Product::where('active', self::STATUS_ACTIVE)->limit(self::STATUS_DELETED + 1)->get();
        $orders = Order::where('customer_email', 'like', '%'.$email.'%')->orderBy('id', 'DESC')->get();
        $orderItem = Order_item::all();
        //$orderItem = DB::select("SELECT * FROM orders join order_items on orders.id = order_items.order_id WHERE orders.customer_email like '%" . $email . "%'");

        $carts = Cart::content();
        foreach ($carts as $keyCart => $cartData) {
            $totalMoney +=  $cartData->price * $cartData->qty;
            $quantity += $cartData->qty;
        }

        return view('/web/order/show', compact('orders', 'orderItem', 'products'))->with('quantity', $quantity)->with('totalMoney', $totalMoney);
    }

    public function showItem($id)
    {
        $totalMoney = 0;
        $quantity = 0;
        $totalMoneyOrder = 0;
        $quantityOrder = 0;

        $products  = Product::where('active', self::STATUS_ACTIVE)->limit(self::STATUS_DELETED + 1)->get();

        $order = Order::find($id);
        $itemOrder = DB::select("SELECT * FROM orders join order_items on orders.id = order_items.order_id WHERE orders.customer_email like '%" . $order->customer_email . "%' and orders.id =" . $order->id);

        $carts = Cart::content();
        foreach ($carts as $keyCart => $cartData) {
            $totalMoney +=  $cartData->price * $cartData->qty;
            $quantity += $cartData->qty;
        }

        foreach ($itemOrder as $keyCart => $itemOrderData) {
            $totalMoneyOrder +=  $itemOrderData->product_price * $itemOrderData->product_quantity;
            $quantityOrder += $itemOrderData->product_quantity;
        }

        $productBestSell = Product::where('active', self::STATUS_ACTIVE)->where('is_best_sell', '=', self::STATUS_ACTIVE)->orderBy('sort_order', 'ASC')->paginate(2); 

        return view('web/order/detail', compact('products', 'order', 'itemOrder', 'productBestSell'))->with('quantity', $quantity)->with('totalMoney', $totalMoney)->with('quantityOrder', $quantityOrder)->with('totalMoneyOrder', $totalMoneyOrder);
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update($id)
    {
        $order = Order::find($id);
        $order->status = self::STATUS_ACTIVE;
        $order->save();

        return redirect()->route('indexOrder');
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        //
    }

    public function search(Request $request) {


    }

    public function select_delivery(Request $request)
    {
        $data = $request->all();
        $output = "";
        if ($data['action']) {
            if ($data['action'] == 'provinces') {
                $huyen = District::where('province_id', $data['id'])->get();
                $output .= '<option value="">---Select districts---</option>';
                foreach ($huyen as $h) {
                    $output .= '<option value="' . $h->id . '">' . $h->name . '</option>';
                }
            } else {
                $xa = Ward::where('district_id', $data['id'])->get();
                $output .= '<option value="">---Select wards---</option>';
                foreach ($xa as $x) {
                    $output .= '<option value="' . $x->id . '">' . $x->name . '</option>';
                }
            }
            echo $output;
        }
    }

    public function showbyId($id)
    {
        $categories = Category::where('active', self::STATUS_ACTIVE)->orderBy('sort_order', 'ASC')->get();
        $brands = Brand::where('active', self::STATUS_ACTIVE)->orderBy('sort_order', 'ASC')->get();
        $order = Order::find($id);
        $itemOrder = DB::select("SELECT * FROM orders join order_items on orders.id = order_items.order_id WHERE orders.customer_email like '%" . $order->customer_email . "%' and orders.id =" . $order->id);

        return view('admin/order/detail', compact('categories', 'brands', 'order', 'itemOrder'));
    }
}
