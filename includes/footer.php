    </main>
    <footer class="app-footer">
        &copy; <?= date('Y') ?> LearnX — The Future Learning · <?= e(APP_TAGLINE) ?>
    </footer>
</div>
<?php if (!empty($extraScripts)) foreach ($extraScripts as $s): ?>
<script src="<?= e($s) ?>"></script>
<?php endforeach; ?>
<script src="<?= e(asset('js/app.js')) ?>"></script>
<?php if (!empty($inlineScript)): ?>
<script><?= $inlineScript ?></script>
<?php endif; ?>
</body>
</html>
