    </main>
</div>
<script src="../<?= asset_ver('assets/js/admin.js') ?>"></script>
<?php // Inerte onde nao ha `.form-abas`; carregar sempre evita que cada tela
      // com abas precise lembrar de incluir — e esquecer disso deixaria o
      // formulario com todas as secoes empilhadas, sem erro nenhum. ?>
<script src="../<?= asset_ver('assets/js/form-abas.js') ?>"></script>
</body>
</html>
