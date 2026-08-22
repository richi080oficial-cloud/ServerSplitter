{{-- Esta vista se carga como parte de la composición para inyectar el script --}}
@push('scripts')
    @include('serversplitter::client.inject-menu')
@endpush