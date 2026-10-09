<?php
// modules/chatbot/layouts/chat_widget.php
// El globo flotante del asistente (MonteBot, 2026-10-07). Lo incluye sidebar.php en todas las pantallas
// privadas, solo para quien tiene el permiso modulo_chatbot. Todo lo que hace está en chatbot.js; acá
// van el HTML y la dirección y el token que usa (en data-, para que el JS sea el mismo en Laravel).
?>
<link rel="stylesheet" href="<?php echo BASE_URL; ?>/modules/chatbot/layouts/chatbot.css?v=<?php echo assetVersion(ROOT_PATH . '/modules/chatbot/layouts/chatbot.css'); ?>">
<button type="button" class="chatbot-burbuja" id="chatbotBurbuja" title="Asistente MonteBot" aria-label="Abrir el asistente"
        data-url="<?php echo BASE_URL; ?>/chatbot/acciones"
        data-csrf="<?php echo htmlspecialchars(generarTokenCSRF(), ENT_QUOTES, 'UTF-8'); ?>">
    <i class="fa-solid fa-robot"></i>
</button>
<section class="chatbot-panel" id="chatbotPanel" role="dialog" aria-label="Asistente MonteBot" aria-hidden="true">
    <header class="chatbot-cabecera">
        <div class="chatbot-titulo">
            <i class="fa-solid fa-robot"></i>
            <div><strong>MonteBot</strong><small>Asistente de Monterojo · escribí / para acciones</small></div>
        </div>
        <div class="chatbot-cabecera-botones">
            <button type="button" id="chatbotLimpiar" title="Borrar la conversación" aria-label="Borrar la conversación"><i class="fa-solid fa-trash-can"></i></button>
            <button type="button" id="chatbotCerrar" title="Cerrar" aria-label="Cerrar el asistente"><i class="fa-solid fa-xmark"></i></button>
        </div>
    </header>
    <div class="chatbot-mensajes" id="chatbotMensajes" aria-live="polite"></div>
    <div class="chatbot-acciones" id="chatbotAcciones" hidden>
        <p class="chatbot-acciones-titulo">Acciones</p>
        <div id="chatbotAccionesLista"></div>
    </div>
    <form class="chatbot-form" id="chatbotForm" autocomplete="off">
        <input type="text" class="chatbot-input" id="chatbotInput" maxlength="2000"
               placeholder="Preguntá algo, o escribí / para acciones…" aria-label="Tu pregunta">
        <button type="submit" class="chatbot-enviar" id="chatbotEnviar" title="Enviar" aria-label="Enviar"><i class="fa-solid fa-paper-plane"></i></button>
    </form>
</section>
<script src="<?php echo BASE_URL; ?>/modules/chatbot/layouts/chatbot.js?v=<?php echo assetVersion(ROOT_PATH . '/modules/chatbot/layouts/chatbot.js'); ?>"></script>
