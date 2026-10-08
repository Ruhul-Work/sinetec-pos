@extends('auth.master')

@section('content')
    @if (session('fw_block_msg'))
        <div class="alert alert-danger mx-5 my-3">{{ session('fw_block_msg') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-danger">
            {{ $errors->first() }}
        </div>
    @endif
    <section class="auth bg-base d-flex flex-wrap">
        <div class="auth-left d-lg-block d-none">
            <div class="d-flex align-items-center flex-column h-100 justify-content-center bg-white">
                <img src={{ asset('theme/admin/assets/images/auth/login.png') }} alt="">
            </div>
        </div>
        <div class="auth-right py-32 px-24 d-flex flex-column justify-content-center">
            <div class="max-w-464-px mx-auto w-100">
                <div>
                    <a href="index.html" class="mb-40 max-w-290-px d-flex justify-content-center">
                        @php
                            $logoUrl = optional($companySetting)->logo
                                ? image($companySetting->logo)
                                : asset('theme/admin/assets/images/logo1.png');
                        @endphp
                        <img src={{ $logoUrl }} alt=""
                            style="width: 168px; height: auto;">
                    </a>
                    <h4 class="mb-12">Sign In to your Account</h4>
                    <p class="mb-32 text-secondary-light text-lg">Welcome back! please enter your detail</p>
                </div>
                <form method="POST" action="{{ route('backend.login.action') }}">
                    {{-- CSRF Token --}}
                    @csrf
                    <div class="icon-field mb-16">
                        <span class="icon top-50 translate-middle-y">
                            <iconify-icon icon="mage:email"></iconify-icon>
                        </span>
                        <input type="text" name="identifier" class="form-control h-56-px bg-neutral-50 radius-12"
                            placeholder="Username or Email">
                    </div>
                    <div class="position-relative mb-20">
                        <div class="icon-field">
                            <span class="icon top-50 translate-middle-y">
                                <iconify-icon icon="solar:lock-password-outline"></iconify-icon>
                            </span>
                            <input type="password" name="password" class="form-control h-56-px bg-neutral-50 radius-12"
                                id="your-password" placeholder="Password">
                        </div>
                        <span
                            class="toggle-password ri-eye-line cursor-pointer position-absolute end-0 top-50 translate-middle-y me-16 text-secondary-light"
                            data-toggle="#your-password"></span>
                    </div>
                    <div class="">
                        <div class="d-flex justify-content-between gap-2">
                            <div class="form-check style-check d-flex align-items-center">
                                <input class="form-check-input border border-neutral-300" type="checkbox" name="remember" value="1"
                                    id="remeber">
                                <label class="form-check-label" for="remeber">Remember me </label>
                            </div>
                            {{-- <a href="javascript:void(0)" class="text-primary-600 fw-medium">Forgot Password?</a> --}}
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary text-sm btn-sm px-12 py-16 w-100 radius-12 mt-32"> Sign
                        In</button>

                    {{-- <div class="mt-32 center-border-horizontal text-center">
                        <span class="bg-base z-1 px-4">Or sign in with</span>
                    </div>
                    <div class="mt-32 d-flex align-items-center justify-content-center ">

                        <button type="button"
                            class="fw-semibold text-primary-light py-16 px-24 w-50 border radius-12 text-md d-flex align-items-center justify-content-center gap-12 line-height-1 bg-hover-primary-50">
                            <iconify-icon icon="logos:google-icon"
                                class="text-primary-600 text-xl line-height-1"></iconify-icon>
                            Google
                        </button>
                    </div> --}}
                    <div class="mt-32 text-center text-sm">
                        <p class="mb-0">Don’t have an account?<a href="{{ route('backend.register') }}"
                                class="text-primary-600 fw-semibold">Sign Up</a></p>
                    </div>

                </form>
            </div>
        </div>
    </section>
@endsection

@section('meta')
    <title>Login - </title>
    <meta name="description">
@endsection

@section('script')
    <script src="{{ asset('theme/admin/assets/plugins/sweetalert/sweetalert2.all.min.js') }}"></script>
    <script src="{{ asset('theme/admin/assets/plugins/sweetalert/sweetalerts.min.js') }}"></script>

    <script>
        $(document).ready(function() {
            // Toggle Password Visibility
            $('.toggle-password').on('click', function() {
                const target = $(this).data('toggle');
                const input = $(target);

                if (input.attr('type') === 'password') {
                    input.attr('type', 'text');
                    $(this).removeClass('ri-eye-line').addClass('ri-eye-off-line');
                } else {
                    input.attr('type', 'password');
                    $(this).removeClass('ri-eye-off-line').addClass('ri-eye-line');
                }
            });
        });
    </script>
@endsection
