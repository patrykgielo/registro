@extends('layouts.app')

@push('head')
    <meta property="og:title" content="{{ $metaTitle }}">
    @if($metaDescription)
        <meta property="og:description" content="{{ $metaDescription }}">
    @endif
    <meta property="og:type" content="website">
    <meta property="og:url" content="{{ route('post.show', $post->slug) }}">
    @if($post->featured_image)
        <meta property="og:image" content="{{ Storage::url($post->featured_image) }}">
    @endif
@endpush

@section('content')
{{--
    Post Display Template

    Uses CMS Layout Wrapper component with PageLayout enum.
    Layout field determines presentation: default, full-width, or minimal.
--}}

<x-cms.layout-wrapper :layout="$layout" :model="$post" type="post" :relatedPosts="$relatedPosts ?? collect()" />
@endsection
