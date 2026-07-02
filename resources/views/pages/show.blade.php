@extends('layouts.app')

@push('head')
    <meta property="og:title" content="{{ $metaTitle }}">
    @if($metaDescription)
        <meta property="og:description" content="{{ $metaDescription }}">
    @endif
    <meta property="og:type" content="website">
    <meta property="og:url" content="{{ route('page.show', $page->slug) }}">
    @if($page->featured_image)
        <meta property="og:image" content="{{ Storage::url($page->featured_image) }}">
    @endif
@endpush

@section('content')
{{--
    Page Display Template

    Uses CMS Layout Wrapper component with PageLayout enum.
    Layout field determines presentation: default, full-width, minimal, or home.
--}}

<x-cms.layout-wrapper :layout="$layout" :model="$page" type="page" :recentPosts="$recentPosts ?? collect()" />
@endsection
