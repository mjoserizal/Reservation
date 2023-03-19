<?php

namespace App\Http\Controllers\Auth;

use App\Models\User;
use Twilio\Rest\Client;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Auth\Events\Registered;
use App\Providers\RouteServiceProvider;
use Illuminate\Validation\Rules\Unique;
use Illuminate\Validation\Rules\Password;
use Symfony\Component\HttpFoundation\JsonResponse;

class RegisteredUserController extends Controller
{
    /**
     * Display the registration view.
     *
     * @return \Illuminate\View\View
     */
    public function create()
    {
        return view('auth.register');
    }

    /**
     * Handle an incoming registration request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\RedirectResponse
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(Request $request)
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'phone_number'  => ['required', 'numeric', 'unique:users'],

        ]);




        $sid                = env("TWILIO_AUTH_SID");
        $token              = env("TWILIO_AUTH_TOKEN");
        $twilio_verify_sid  = env("TWILIO_VERIFY_SID");

        $twilio = new Client($sid, $token);

        $twilio->verify->v2->services($twilio_verify_sid)
            ->verifications
            ->create($request['phone_number'], "whatsapp");

        $user = User::create([


            'name' => $request->name,
            'email' => $request->email,
            'phone_number' => $request->phone_number,
            'password' => Hash::make($request->password),
            // 'phone_number' => $request->phone_number,
            // 'isVerified' => $request->isverified
        ]);

        event(new Registered($user));

        Auth::login($user);

        return redirect(RouteServiceProvider::HOME)
            ->with(['phone_number' => $request['phone_number']]);
    }


    protected function verify(Request $request)
    {
        $data = $request->validate([
            'verification_code' => ['required', 'numeric'],
            'phone_number'      => ['required', 'string'],
        ]);

        $sid                = env("TWILIO_AUTH_SID");
        $token              = env("TWILIO_AUTH_TOKEN");
        $twilio_verify_sid  = env("TWILIO_VERIFY_SID");

        $twilio = new Client($sid, $token);

        // $verification = $twilio->verify->v2->services($twilio_verify_sid)
        //     ->verificationChecks
        //     ->create($data['verification_code'], array('to' => $data['phone_number']));

        $verification = $twilio->verify->v2->services($twilio_verify_sid)
            ->verificationChecks
            ->create(['code' => $data['verification_code'], 'to' => $data['phone_number']]);

        if ($verification->valid) {
            $user = tap(User::where('phone_number', $data['phone_number']))->update(['isVerified' => true]);
            Auth::login($user->first());
            return redirect()->route('verify')
                ->with(['message' => 'Your account has been verified']);
        }

        return back()->with([
            'phone_number' => $data['phone_number'],
            'error' => 'Invalid verification code entered!'
        ]);
    }

    public function register(Request $request)
    {
        $this->validator($request->all())->validate();
        event(new Registered($user = $this->create($request->all())));
        if ($response = $this->registered($request, $user)) {
            return $response;
        }
        return $request->wantsJson()
            ? new JsonResponse([], 201)
            : redirect()->route('verify')->with(['phone_number' => $request->phone_number]);
    }
}
