@extends('profile.layout')

@section('profile-content')
    @include('profile.partials.tab-security')
@endsection

{{-- Modals --}}
@include('profile.modals.delete-account')
@include('profile.modals.change-email')
@include('profile.modals.data-export')
