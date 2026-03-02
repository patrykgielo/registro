@extends('layouts.app')

@section('title', 'Homepage Not Configured')

@section('content')
<div class="container mx-auto px-4 py-24 text-center">
    <svg class="w-16 h-16 text-gray-400 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
    </svg>
    <h1 class="text-3xl font-bold text-gray-900 mb-2">Homepage Not Configured</h1>
    <p class="text-gray-600 mb-6">Please configure homepage in admin panel.</p>

    @can('viewAny', \App\Models\Page::class)
        <a href="/admin/system-settings" class="inline-block px-6 py-3 bg-primary-600 text-white rounded-lg hover:bg-primary-700 transition-colors">
            Configure Homepage
        </a>
    @endcan
</div>
@endsection
