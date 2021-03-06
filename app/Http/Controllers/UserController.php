<?php

namespace App\Http\Controllers;

use App\Group;
use App\Mailers\AppMailer;
use App\User;
use Auth;
use File;
use Gate;
use Illuminate\Http\Request;
use Image;
use Mail;
use Redirect;
use Storage;

use App\Mail\ContactUser;

class UserController extends Controller
{
    public function __construct()
    {
        $this->middleware('cache', ['only' => ['cover', 'avatar']]);
        $this->middleware('verified', ['only' => ['contact', 'mail']]);
        $this->middleware('throttle:2,1', ['only' => ['mail', 'sendVerificationAgain']]); // 2 emails per  minute should be enough for non bots
    }

    /**
    * Display a listing of the resource.
    *
    * @return Response
    */
    public function index(Group $group)
    {
        $users = $group->users()->with('memberships')->orderBy('updated_at', 'desc')->paginate(25);
        $admins = $group->admins()->orderBy('name')->get();

        return view('users.index')
        ->with('users', $users)
        ->with('admins', $admins)
        ->with('group', $group)
        ->with('tab', 'users');
    }

    /**
    * Show contact form for the user.
    */
    public function contact(User $user)
    {
        return view('users.contact')
        ->with('tab', 'contact')
        ->with('user', $user);
    }

    /**
    * Mails the user.
    */
    public function mail(User $user, Request $request)
    {
        $from_user = Auth::user();

        if ($request->has('body'))
        {
            $body = $request->input('body');
            Mail::to($user)->send(new ContactUser(Auth::user(), $user, $body));

            flash(trans('messages.message_sent'))->success();
            return redirect()->action('UserController@contact', $user->id);
        }

        return redirect()->action('UserController@contact', $user->id);
    }

    /**
    * Display the specified resource.
    *
    * @param int $id
    *
    * @return Response
    */
    public function show(User $user)
    {
        return view('users.show')
        ->with('user', $user)
        ->with('tab', 'profile');
    }

    /**
    * Show the form for editing the specified resource.
    *
    * @param int $id
    *
    * @return Response
    */
    public function edit(User $user)
    {
        if (Gate::allows('update', $user)) {
            return view('users.edit')
            ->with('user', $user)->with('tab', 'edit');
        } else {
            abort(403);
        }
    }

    /**
    * Update the specified resource in storage.
    *
    * @param int $id
    *
    * @return Response
    */
    public function update(Request $request, User $user)
    {
        if (Gate::allows('update', $user)) {
            $user->name = $request->input('name');

            $previous_email = $user->email;
            $user->email = $request->input('email');
            $user->body = $request->input('body');

            if ($user->address != $request->input('address')) {
                // we need to update user address and geocode it
                $user->address = $request->input('address');
                if (!$user->geocode()) {
                    flash(trans('messages.address_cannot_be_geocoded'))->warning();
                } else {
                    flash(trans('messages.ressource_geocoded_successfully'));
                }
            }

            // handle the case the edit form is used to make a user an admin (or remove admin right)
            if (Auth::user()->isAdmin()) {
                if ($request->get('is_admin') == 'yes') {
                    $user->admin = 1;
                }

                if ($request->get('is_admin') == 'no') {
                    $user->admin = 0;
                }
            }

            // validation
            if ($user->isInvalid()) {
                // Oops.
                return redirect()->action('UserController@edit', $user->id)
                ->withErrors($user->getErrors())
                ->withInput();
            }

            // handle cover
            if ($request->hasFile('cover')) {
                Storage::disk('local')->makeDirectory('users/'.$user->id);
                Image::make($request->file('cover'))->widen(500)->save(storage_path().'/app/users/'.$user->id.'/cover.jpg');
                Image::make($request->file('cover'))->fit(128, 128)->save(storage_path().'/app/users/'.$user->id.'/thumbnail.jpg');
            }

            // handle email change : if a user changes his email, we set him/her to unverified, and send a new verification email
            if ($previous_email != $user->email) {
                $user->verified = 0;
                $user->token = str_random(30);
                $mailer = new AppMailer();
                $mailer->sendEmailConfirmationTo($user);
            }

            $user->save();

            flash(trans('messages.ressource_updated_successfully'))->success();

            return redirect()->action('UserController@show', [$user->id]);
        } else {
            abort(403);
        }
    }

    /**
    * Send verification token to a user, again, for example if it's stuck in spam or wathever else event.
    *
    * @param Request $request
    * @param int     $id      User id
    *
    * @return Flash message and returns to homepage
    */
    public function sendVerificationAgain(Request $request, User $user)
    {
        if ($user->verified == 0) {
            $mailer = new AppMailer();
            $mailer->sendEmailConfirmationTo($user);
            flash(trans('messages.invitation_sent_again'));

            return redirect()->action('UserController@show', [$user->id]);
        }
    }

    /**
    * Remove the specified resource from storage.
    *
    * @param int $id
    *
    * @return Response
    */
    public function destroy(User $user)
    {
    }

    public function cover(User $user)
    {
        $path = storage_path().'/app/users/'.$user->id.'/cover.jpg';

        if (File::exists($path)) {
            $cachedImage = Image::cache(function ($img) use ($path) {
                return $img->make($path)->fit(400, 400);
            }, 60000, true);

            return $cachedImage->response();
        } else {
            return Redirect::to(url('/images/avatar.jpg'));
        }
    }

    public function avatar(User $user)
    {
        $path = storage_path().'/app/users/'.$user->id.'/cover.jpg';

        if (File::exists($path)) {
            $cachedImage = Image::cache(function ($img) use ($path) {
                return $img->make($path)->fit(128, 128);
            }, 60000, true);

            return $cachedImage->response();
        } else {
            return Redirect::to(url('/images/avatar.jpg'));
        }
    }
}
