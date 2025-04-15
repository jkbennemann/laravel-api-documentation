<!DOCTYPE html>
<html>
<head>
    <title>ReDoc</title>
    <!-- needed for adaptive design -->
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://fonts.googleapis.com/css?family=Montserrat:300,400,700|Roboto:300,400,700" rel="stylesheet">

    <!--
    ReDoc doesn't change outer page styles
    -->
    <style>
        body {
            margin: 0;
            padding: 0;
        }
        #doc-switcher {
            position: fixed;
            top: 10px;
            left: 50vw;
            z-index: 1000;
            padding: 5px;
        }
    </style>
</head>
<body>

<select id="doc-switcher">
    @isset($documentationFile)
        <option value="<?php echo $documentationFile; ?>">Default</option>
    @endisset
    @if(!empty($documentationFiles))
        @foreach($documentationFiles as $file)
            <option value="{!! $file['filename'] !!}">{!! $file['name'] !!}</option>
        @endforeach
    @endif
</select>

<div id="redoc-container"></div>

<script src="https://cdn.jsdelivr.net/npm/redoc@<?php echo $redocVersion; ?>/bundles/redoc.standalone.js"></script>
<script>
    const container = document.getElementById('redoc-container');
    const switcher = document.getElementById('doc-switcher');

    function loadRedoc(specUrl) {
        container.innerHTML = '';
        Redoc.init(specUrl, {}, container);
    }

    switcher.addEventListener('change', function() {
        loadRedoc(this.value);
    });

    loadRedoc(switcher.value);
</script>

</body>
</html>
