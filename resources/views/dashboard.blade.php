@extends('layouts.app')
@section('title', 'หน้าหลัก')

@section('content')
<script>window.location.href = "{{ route('account.dashboard') }}";</script>
<noscript><meta http-equiv="refresh" content="0;url={{ route('account.dashboard') }}"></noscript>
@endsection
