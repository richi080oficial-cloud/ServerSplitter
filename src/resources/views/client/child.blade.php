@extends('serversplitter::client.layout')

@section('title', 'ServerSplitter - ' . $server->name)

@section('content')
    @include('serversplitter::client.child-content')
@endsection
