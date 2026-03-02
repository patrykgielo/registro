@extends('layouts.app')

@section('content')
{{--
    Post Display Template

    Uses CMS Layout Wrapper component with PageLayout enum.
    Layout field determines presentation: default, full-width, or minimal.
--}}

<x-cms.layout-wrapper :layout="$layout" :model="$post" type="post" :relatedPosts="$relatedPosts ?? collect()" />
@endsection
