@extends('layouts.app')

@section('content')
{{--
    Promotion Display Template

    Uses CMS Layout Wrapper component with PageLayout enum.
    Layout field determines presentation: default, full-width, or minimal.
--}}

<x-cms.layout-wrapper :layout="$layout" :model="$promotion" type="promotion" :recentPosts="$recentPosts ?? collect()" />
@endsection
