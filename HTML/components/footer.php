<?php
declare(strict_types=1);
?>
<footer class="app-footer mt-auto">
    <div class="container-fluid px-3 px-lg-4">
        <div class="row gy-3 align-items-center">
            <div class="col-lg-5">
                <p class="mb-1 fw-semibold">Portafolio Académico Inteligente</p>
                <p class="mb-0 text-secondary">Impulsando a estudiantes a mostrar su futuro profesional.</p>
            </div>
            <div class="col-lg-7">
                <ul class="footer-contact justify-content-lg-end">
                    <li>
                        <i class="bi bi-envelope" aria-hidden="true"></i>
                        <a href="mailto:satb080929hnellra5@soycecytem.mx">satb080929hnellra5@soycecytem.mx</a>
                    </li>
                    <li>
                        <i class="bi bi-instagram" aria-hidden="true"></i>
                        <a href="https://www.instagram.com/bryant.v1" target="_blank" rel="noopener">bryant.v1</a>
                    </li>
                    <li>
                        <i class="bi bi-telephone" aria-hidden="true"></i>
                        <a href="tel:+525669230448">+52 5669230448</a>
                    </li>
                </ul>
            </div>
        </div>
        <hr>
    </div>
</footer>

<dialog id="appConfirmDialog" class="app-confirm-dialog" aria-labelledby="appConfirmTitle">
    <form method="dialog" class="app-confirm-dialog-body">
        <h2 id="appConfirmTitle">Confirmar acción</h2>
        <p id="appConfirmMessage">¿Confirmas esta acción?</p>
        <div class="app-confirm-actions">
            <button class="btn btn-outline-primary" type="button" id="appConfirmCancel" value="cancel">Cancelar</button>
            <button class="btn btn-danger" type="button" id="appConfirmAccept" value="confirm">Confirmar</button>
        </div>
    </form>
</dialog>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= e(asset_url('js/main.js')); ?>"></script>
<?php if (!empty($pageScript)): ?>
    <script src="<?= e(asset_url('js/' . $pageScript)); ?>"></script>
<?php endif; ?>
</body>
</html>
