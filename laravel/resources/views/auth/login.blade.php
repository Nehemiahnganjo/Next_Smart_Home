@extends('layouts.app')
@section('title', 'Sign in')

@section('content')
<div class="min-h-screen flex items-center justify-center px-4">
    <div class="w-full max-w-sm bg-white rounded-xl shadow p-8">
        <h1 class="text-xl font-semibold mb-6">Sign in</h1>

        @if ($errors->any())
            <div class="mb-4 text-sm text-red-600 bg-red-50 rounded-lg p-3">
                {{ $errors->first() }}
            </div>
        @endif

        <form method="POST" action="{{ route('login') }}" class="space-y-4">
            @csrf
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                <input type="email" name="email" value="{{ old('email') }}" required autofocus
                       class="w-full rounded-lg border-gray-300 focus:border-blue-500 focus:ring-blue-500">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Password</label>
                <input type="password" name="password" required
                       class="w-full rounded-lg border-gray-300 focus:border-blue-500 focus:ring-blue-500">
            </div>
            <label class="flex items-center gap-2 text-sm text-gray-600">
                <input type="checkbox" name="remember" class="rounded border-gray-300">
                Remember me
            </label>
            <button type="submit"
                    class="w-full bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg py-2 transition">
                Sign in
            </button>
        </form>
    </div>
</div>
@endsection
