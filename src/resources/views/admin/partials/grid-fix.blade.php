{{--
    ServerSplitter - rejilla de columnas autocontenida para las paginas de
    admin.

    Los formularios de la extension usan clases al estilo Bootstrap 3 / AdminLTE
    (row, col-xs-12, col-sm-6, col-md-4...) porque es lo que trae el panel de
    Pterodactyl "de fabrica". El problema es que algunos temas de terceros
    sustituyen por completo esa hoja de estilos (o la version de Bootstrap) sin
    definir esas mismas clases, asi que nuestras columnas no flotan/envuelven
    correctamente y el contenido queda cortado por el borde derecho.

    Este include define nuestra propia rejilla, con especificidad suficiente
    (todo bajo .ss-admin) para no depender de que el tema instalado provea
    Bootstrap: funciona igual con AdminLTE de serie que con un tema que no
    tenga ninguna clase "col-*" propia.
--}}
<style>
    .ss-admin, .ss-admin * {
        box-sizing: border-box;
    }

    /* Si algo se nos escapa de calculo, que haga scroll en vez de recortarse. */
    .ss-admin {
        width: 100%;
        max-width: 100%;
        overflow-x: hidden;
        display: block;
        clear: both;
    }

    .ss-admin .row {
        display: flex;
        flex-wrap: wrap;
        margin-left: 0;
        margin-right: 0;
    }

    .ss-admin [class*="col-"] {
        padding-left: 0;
        padding-right: 0;
        width: 100%;
        flex: 0 0 100%;
        max-width: 100%;
    }

    .ss-admin .form-group {
        margin-bottom: 15px;
    }

    @media (min-width: 576px) {
        .ss-admin .col-xs-6 { flex: 0 0 50%; max-width: 50%; }
    }

    @media (min-width: 768px) {
        .ss-admin .col-sm-4 { flex: 0 0 33.3333%; max-width: 33.3333%; }
        .ss-admin .col-sm-6 { flex: 0 0 50%; max-width: 50%; }
        .ss-admin .col-sm-8 { flex: 0 0 66.6667%; max-width: 66.6667%; }
    }

    @media (min-width: 992px) {
        .ss-admin .col-md-3 { flex: 0 0 25%; max-width: 25%; }
        .ss-admin .col-md-4 { flex: 0 0 33.3333%; max-width: 33.3333%; }
        .ss-admin .col-md-5 { flex: 0 0 41.6667%; max-width: 41.6667%; }
        .ss-admin .col-md-6 { flex: 0 0 50%; max-width: 50%; }
        .ss-admin .col-md-7 { flex: 0 0 58.3333%; max-width: 58.3333%; }
        .ss-admin .col-md-8 { flex: 0 0 66.6667%; max-width: 66.6667%; }
    }
</style>
