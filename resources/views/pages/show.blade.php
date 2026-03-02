@extends('layouts.app')

@section('content')
{{--
    Page Display Template

    Uses CMS Layout Wrapper component with PageLayout enum.
    Layout field determines presentation: default, full-width, minimal, or home.
--}}

<x-cms.layout-wrapper :layout="$layout" :model="$page" type="page" :recentPosts="$recentPosts ?? collect()" />
@endsection
