{{--
    Checkbox accepting an agreement in force; renders nothing while the
    agreement has no published version. The agreement text opens in a
    modal (a new tab on touch devices); the modal and openModal(url) come
    with the first checkbox of the page.
--}}
@props(['key', 'name' => 'agreement'])

@if ($version = app(\App\Support\Agreements::class)->current($key))
    <div class="row">
        <label class="col-md-8 offset-md-4 mb-3" for="{{ $name }}-{{ $key }}">
            <input name="{{ $name }}" id="{{ $name }}-{{ $key }}" value="true" type="checkbox" required>
            &nbsp; <a href="{{ route('agreements.show', $key, false) }}" target="_blank" onclick="return openModal(this.href)">{{ $version->agreement->title }}</a> koşullarını kabul ediyorum
        </label>
    </div>

    @once
        <div class="modal" id="modal-iframe" tabindex="-1" role="dialog" aria-hidden="true">
            <div class="modal-dialog modal-xl" role="document" style="border: 1px solid #ccc;">
                <div class="modal-content">
                    <div class="modal-body mb-0 p-0">
                        <iframe style="border:0; width:100%; height: 500px;" id="iframe-content" src="about:blank" title="Sözleşme"></iframe>
                    </div>
                    <div class="modal-footer justify-content-center">
                        <button type="button" class="btn btn-warning" onclick="document.getElementById('modal-iframe').style.display = 'none';">Kapat</button>
                    </div>
                </div>
            </div>
        </div>

        <script>
            // Returns false so the link does not also open the page itself.
            function openModal(url) {
                if (window.matchMedia('(pointer: coarse)').matches) {
                    window.open(url);

                    return false;
                }
                document.getElementById('iframe-content').src = url + (url.indexOf('?') === -1 ? '?' : '&') + 'iframe';
                document.getElementById('modal-iframe').style.display = 'block';

                return false;
            }
        </script>
    @endonce
@endif
