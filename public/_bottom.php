</main>
<footer class="footer">
  <span><?= e((string)setting('app_title', 'My Agenda')) ?></span>
  <a href="<?= e(base_url()) ?>/calendar.php"><?= t('calendar_view') ?></a>
  <a href="<?= e(base_url()) ?>/book.php"><?= t('book') ?></a>
</footer>
<script src="<?= e(base_url()) ?>/assets/js/app.js"></script>
</body>
</html>
