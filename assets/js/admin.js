const jQuery = window.jQuery;
//const marpico_ajax = window.marpico_ajax

jQuery(($) => {
  let syncInProgress = false;
  let syncPaused = false;
  let currentOffset = 0;
  let retryCount = 0;
  const maxRetries = 3;
  let syncStartTime = null;
  let batchStartTime = null;
  let syncMode = "batch"; // 'batch' or 'individual'

  function formatElapsedTime(startTime) {
    const elapsed = Date.now() - startTime;
    const seconds = Math.floor(elapsed / 1000);
    const minutes = Math.floor(seconds / 60);
    const hours = Math.floor(minutes / 60);

    if (hours > 0) {
      return `${hours}h ${minutes % 60}m ${seconds % 60}s`;
    } else if (minutes > 0) {
      return `${minutes}m ${seconds % 60}s`;
    } else {
      return `${seconds}s`;
    }
  }

  function getCurrentTimestamp() {
    const now = new Date();
    return now.toLocaleString("es-ES", {
      year: "numeric",
      month: "2-digit",
      day: "2-digit",
      hour: "2-digit",
      minute: "2-digit",
      second: "2-digit",
    });
  }

  function addLogEntry(message, type = "info") {
    const timestamp = getCurrentTimestamp();
    const logEntry = `[${timestamp}] ${message}`;

    const logContainer = $("#marpico-logs-container");
    if (logContainer.length) {
      const logClass = `marpico-log-${type}`;
      const logHtml = `
        <div class="marpico-log-entry ${logClass}">
          <span class="marpico-log-timestamp">[${timestamp}]</span>
          <span class="marpico-log-message">${message}</span>
        </div>
      `;
      logContainer.prepend(logHtml);

      if (logContainer.children().length > 100) {
        logContainer.children().slice(100).remove();
      }

      if (
        logContainer.scrollTop() + logContainer.innerHeight() >=
        logContainer[0].scrollHeight - 50
      ) {
        logContainer.scrollTop(logContainer[0].scrollHeight);
      }
    }

    console.log(`[v0] ${logEntry}`);
  }

  function initializeModernInterface() {
    $(".marpico-sync-option").on("click", function () {
      $(".marpico-sync-option").removeClass("active");
      $(this).addClass("active");

      const mode = $(this).data("mode");
      syncMode = mode;

      if (mode === "individual") {
        $("#individual-sync-form")
          .removeClass("marpico-hidden")
          .addClass("marpico-fade-in");
        $("#batch-sync-form").addClass("marpico-hidden");
        addLogEntry("Modo individual seleccionado", "info");
      } else {
        $("#batch-sync-form")
          .removeClass("marpico-hidden")
          .addClass("marpico-fade-in");
        $("#individual-sync-form").addClass("marpico-hidden");
        addLogEntry("Modo por lotes seleccionado", "info");
      }
    });

    $("#sync-individual-product").on("click", (e) => {
      e.preventDefault();
      const productCode = $("#product-code-input").val().trim();

      if (!productCode) {
        addLogEntry("Error: Debe ingresar un código de producto", "error");
        return;
      }

      syncIndividualProduct(productCode);
    });

    updateStats();
  }

  function syncIndividualProduct(productCode) {
    const btn = $("#sync-individual-product");
    btn.prop("disabled", true).text("Sincronizando...");

    syncStartTime = Date.now();
    addLogEntry(
      `Iniciando sincronización del producto: ${productCode}`,
      "info"
    );

    $.post(
      marpico_ajax.ajax_url,
      {
        action: "marpico_sync_product_individual",
        security: marpico_ajax.nonce,
        product_code: productCode,
      },
      (resp) => {
        const elapsed = formatElapsedTime(syncStartTime);

        if (resp.success) {
          const message = `Producto ${productCode} sincronizado exitosamente en ${elapsed}`;
          addLogEntry(message, "success");
          $("#individual-sync-status").html(
            `<span class="marpico-log-success">✓ ${message}</span>`
          );
          $("#product-code-input").val("");
          updateStats();
        } else {
          const message = `Error sincronizando producto ${productCode}: ${
            resp.data || "Error desconocido"
          }`;
          addLogEntry(message, "error");
          $("#individual-sync-status").html(
            `<span class="marpico-log-error">❌ ${message}</span>`
          );
        }
        btn.prop("disabled", false).text("Sincronizar Producto");
      }
    ).fail((xhr) => {
      const elapsed = formatElapsedTime(syncStartTime);
      const message = `Error de conexión sincronizando producto ${productCode} después de ${elapsed}`;
      addLogEntry(message, "error");
      $("#individual-sync-status").html(
        `<span class="marpico-log-error">❌ ${message}</span>`
      );
      btn.prop("disabled", false).text("Sincronizar Producto");
    });
  }

  function updateStats() {
    $.post(
      marpico_ajax.ajax_url,
      {
        action: "marpico_get_sync_stats",
        security: marpico_ajax.nonce,
      },
      (resp) => {
        if (resp.success) {
          const stats = resp.data;
          $("#total-products-stat").text(stats.total_products || "0");
          $("#last-sync-stat").text(stats.last_sync || "Nunca");
          $("#sync-errors-stat").text(stats.sync_errors || "0");
          $("#api-status-stat").text(stats.api_status || "Desconocido");
        }
      }
    );
  }

  $("#sync-products-batch").on("click", function (e) {
    e.preventDefault();
    var btn = $(this);

    if (syncInProgress) {
      if (syncPaused) {
        syncPaused = false;
        btn.text("Pausar Sincronización");
        addLogEntry("Sincronización reanudada", "info");
        resumeSync();
      } else {
        syncPaused = true;
        btn.text("Reanudar Sincronización");
        addLogEntry("Sincronización pausada", "warning");
      }
      return;
    }

    syncStartTime = Date.now();
    syncInProgress = true;
    syncPaused = false;
    currentOffset = 0;
    retryCount = 0;

    btn.text("Pausar Sincronización").addClass("marpico-btn-secondary");
    addLogEntry("Iniciando sincronización por lotes de 20 productos", "info");

    $("#marpico-sync-status-batch").html(`
      <div class="marpico-progress-container">
        <div class="marpico-progress-bar">
          <div class="marpico-progress-fill" style="width: 0%"></div>
        </div>
        <div class="marpico-progress-text">Iniciando sincronización...</div>
        <div class="sync-controls">
          <button id="cancel-sync" class="marpico-btn" style="background: #ef4444; color: white;">Cancelar Sincronización</button>
        </div>
      </div>
    `);

    $("#cancel-sync").on("click", () => {
      cancelSync();
    });

    var totalProcessed = 0;
    var totalProducts = 0;
    var batchSize = 10;

    function cancelSync() {
      syncInProgress = false;
      syncPaused = false;
      const elapsedTime = formatElapsedTime(syncStartTime);
      const message = `Sincronización cancelada. Procesados ${totalProcessed} productos en ${elapsedTime}`;

      $("#marpico-sync-status-batch").html(
        `<span style="color: orange;">⚠ ${message}</span>`
      );
      addLogEntry(message, "info");
      btn
        .prop("disabled", false)
        .text("Sincronizar Productos por Lotes")
        .removeClass("marpico-btn-secondary");
    }

    function resumeSync() {
      if (!syncPaused && syncInProgress) {
        syncBatch(currentOffset);
      }
    }

    function syncBatch(offset) {
      if (syncPaused || !syncInProgress) {
        return;
      }

      currentOffset = offset;
      batchStartTime = Date.now();

      console.log(
        `[v0] [${getCurrentTimestamp()}] Iniciando lote en offset: ${offset}`
      );
      var currentPercentage =
        totalProducts > 0
          ? Math.round((totalProcessed / totalProducts) * 100)
          : 0;
      $(".marpico-progress-text").text(
        `${currentPercentage}% - Procesando lote desde posición ${offset}...`
      );

      $.post(
        marpico_ajax.ajax_url,
        {
          action: "marpico_sync_products_batch",
          security: marpico_ajax.nonce,
          offset: offset,
          batch_size: batchSize,
        },
        (resp) => {
          const batchElapsed = formatElapsedTime(batchStartTime);
          console.log(
            `[v0] [${getCurrentTimestamp()}] Respuesta recibida en ${batchElapsed}:`,
            resp
          );

          if (resp.success) {
            retryCount = 0;
            var data = resp.data;
            totalProcessed += data.processed;
            totalProducts = data.total;

            var percentage = Math.round((totalProcessed / totalProducts) * 100);

            $(".marpico-progress-fill")
              .css("width", percentage + "%")
              .get(0).offsetHeight;
            $(".marpico-progress-text")
              .text(
                `${percentage}% - Procesados ${totalProcessed} de ${totalProducts} productos`
              )
              .get(0).offsetHeight;

            const logMessage = `Lote completado: ${data.processed} productos en ${batchElapsed} (${percentage}% total)`;
            addLogEntry(logMessage, "success");
            console.log(`[v0] [${getCurrentTimestamp()}] ${logMessage}`);

            updateStats();

            if (data.has_more && syncInProgress && !syncPaused) {
              setTimeout(() => {
                syncBatch(data.next_offset);
              }, 800);
            } else if (!data.has_more) {
              syncInProgress = false;
              const totalElapsed = formatElapsedTime(syncStartTime);
              const completionMessage = `✓ Sincronización completada: ${totalProcessed} productos procesados en ${totalElapsed}`;

              console.log(
                `[v0] [${getCurrentTimestamp()}] ${completionMessage}`
              );
              addLogEntry(completionMessage, "success");

              $("#marpico-sync-status-batch").html(
                `<div class="marpico-log-success" style="padding: 16px; text-align: center; font-weight: 600;">${completionMessage}</div>`
              );
              btn
                .prop("disabled", false)
                .text("Sincronizar Productos por Lotes")
                .removeClass("marpico-btn-secondary");
              updateStats();
            }
          } else {
            const errorMessage = `Error en lote (offset ${offset}): ${
              resp.data || "Error desconocido"
            }`;
            console.log(`[v0] [${getCurrentTimestamp()}] ${errorMessage}`);
            addLogEntry(errorMessage, "error");
            handleSyncError(resp.data || "Error desconocido", offset);
          }
        }
      ).fail((xhr) => {
        const batchElapsed = formatElapsedTime(batchStartTime);
        let errorMsg = "Error de conexión";
        if (xhr.status === 0) {
          errorMsg = "Sin conexión a internet o servidor no disponible";
        } else if (xhr.status === 500) {
          errorMsg = "Error interno del servidor";
        } else if (xhr.status === 504) {
          errorMsg = "Timeout del servidor";
        }

        const fullErrorMsg = `${errorMsg} después de ${batchElapsed} (offset ${offset})`;
        console.log(
          `[v0] [${getCurrentTimestamp()}] Error AJAX: ${xhr.status} ${
            xhr.statusText
          } - ${fullErrorMsg}`
        );
        addLogEntry(fullErrorMsg, "error");
        handleSyncError(errorMsg, offset);
      });
    }

    function handleSyncError(errorMsg, offset) {
      retryCount++;

      if (retryCount <= maxRetries) {
        const retryMessage = `Reintentando lote ${retryCount}/${maxRetries} en 5 segundos (offset ${offset})`;
        addLogEntry(retryMessage, "info");

        $(".marpico-progress-text").html(`
          Error: ${errorMsg}<br>
          <div class="retry-info">${retryMessage}</div>
        `);

        setTimeout(() => {
          if (syncInProgress && !syncPaused) {
            syncBatch(offset);
          }
        }, 5000);
      } else {
        syncInProgress = false;
        const elapsedTime = formatElapsedTime(syncStartTime);
        const finalErrorMsg = `Error después de ${maxRetries} intentos: ${errorMsg}. Procesados ${totalProcessed} productos en ${elapsedTime}`;

        console.log(`[v0] [${getCurrentTimestamp()}] ${finalErrorMsg}`);
        addLogEntry(finalErrorMsg, "error");

        $("#marpico-sync-status-batch").html(`
          <span style="color: red;">❌ ${finalErrorMsg}</span><br>
          <button id="resume-from-error" class="marpico-btn" style="margin-top: 10px; background: #0073aa; color: white;">Continuar desde donde se quedó</button>
        `);

        $("#resume-from-error").on("click", function () {
          retryCount = 0;
          syncInProgress = true;
          syncPaused = false;
          syncStartTime = Date.now(); // Reiniciar tiempo
          btn.text("Pausar Sincronización").addClass("marpico-btn-secondary");
          addLogEntry("Reanudando sincronización desde error", "info");
          $(this)
            .parent()
            .html(
              '<div class="marpico-progress-text">Reanudando sincronización...</div>'
            );
          setTimeout(() => {
            syncBatch(currentOffset);
          }, 1000);
        });

        btn
          .prop("disabled", false)
          .text("Sincronizar Productos por Lotes")
          .removeClass("marpico-btn-secondary");
      }
    }

    syncBatch(0);
  });

  $(document).ready(() => {
    initializeModernInterface();

    setInterval(updateStats, 30000);
  });

  // --- Cargar categorías BestStock al iniciar ---
  $(document).ready(function () {
    const $category = $("#beststock-category");
    const $subcategory = $("#beststock-subcategory");

    // Deshabilitamos select hijo mientras carga
    $subcategory.prop("disabled", true);

    // Llenamos select de categorías padre desde la API
    $.post(
      marpico_ajax.ajax_url,
      { action: "get_beststock_categories", security: marpico_ajax.nonce },
      function (resp) {
        if (resp.success && Array.isArray(resp.data)) {
          let options = '<option value="">Selecciona categoría</option>';
          resp.data.forEach((cat) => {
            // <-- aquí usamos resp.data
            options += `<option value="${cat.id}" data-sub='${JSON.stringify(
              cat.subcategorias
            )}'>${cat.name}</option>`;
          });
          $category.html(options).prop("disabled", false);
        } else {
          $category.html('<option value="">Error cargando categorías</option>');
        }
      }
    ).fail(function () {
      $category.html('<option value="">Error cargando categorías</option>');
    });

    // Cuando cambia categoría principal
    $category.on("change", function () {
      const selected = $(this).find("option:selected");
      let subs = selected.data("sub"); // puede ser undefined

      if (!subs) {
        subs = []; // default a array vacío si no existe
      } else if (typeof subs === "string") {
        try {
          subs = JSON.parse(subs);
        } catch (e) {
          subs = [];
          console.error("Error parseando subcategorias:", e);
        }
      }

      if (subs.length > 0) {
        let options = '<option value="">Selecciona subcategoría</option>';
        subs.forEach((sub) => {
          options += `<option value="${sub.id}">${sub.name}</option>`;
        });
        $subcategory.html(options).prop("disabled", false); // show() ya no es necesario
      } else {
        $subcategory.html("").prop("disabled", true);
      }
    });
  });

  // --- Sincronización por Categoría en Batches ---
  $("#sync-beststock-category").on("click", function (e) {
    e.preventDefault();

    const categoryId = $("#beststock-subcategory").val().trim();
    const parentId = $("#wc-category").val();
    const childId =
      $("#wc-category-child").length && $("#wc-category-child").val()
        ? $("#wc-category-child").val()
        : "";

    if (!categoryId) {
      addLogEntry(
        "Error: Debe ingresar un ID de categoría para BestStock",
        "error"
      );
      return;
    }

    if (!parentId) {
      addLogEntry(
        "Error: Debe seleccionar una categoría de WooCommerce",
        "error"
      );
      return;
    }

    const btn = $(this);
    btn.prop("disabled", true).text("Sincronizando...");

    syncStartTime = Date.now();
    let offset = 0;
    const batchSize = 2; // número de productos por lote
    let syncInProgress = true;
    let syncPaused = false;
    let retryCount = 0;
    const maxRetries = 3;

    $("#api-sync-status").html(`
    <div class="marpico-progress-container">
      <div class="marpico-progress-bar">
        <div class="marpico-progress-fill" style="width: 0%"></div>
      </div>
      <div class="marpico-progress-text">Iniciando sincronización...</div>
      <div class="sync-controls">
        <button id="pause-sync" class="marpico-btn">Pausar Sincronización</button>
        <button id="cancel-sync" class="marpico-btn" style="background: #ef4444; color: white;">Cancelar Sincronización</button>
      </div>
    </div>
  `);

    $("#pause-sync").on("click", () => {
      if (syncPaused) {
        syncPaused = false;
        $("#pause-sync").text("Pausar Sincronización");
        addLogEntry("Sincronización reanudada", "info");
        processBatch();
      } else {
        syncPaused = true;
        $("#pause-sync").text("Reanudar Sincronización");
        addLogEntry("Sincronización pausada", "warning");
      }
    });

    $("#cancel-sync").on("click", () => {
      syncInProgress = false;
      const elapsed = formatElapsedTime(syncStartTime);
      addLogEntry(
        `Sincronización cancelada en ${elapsed} (offset ${offset})`,
        "warning"
      );
      btn.prop("disabled", false).text("Sincronizar Categoría");
      $("#api-sync-status").html("");
    });

    function processBatch() {
      if (!syncInProgress || syncPaused) return;

      $.post(
        marpico_ajax.ajax_url,
        {
          action: "beststock_sync_batch",
          security: marpico_ajax.nonce,
          category_id: categoryId,
          offset: offset,
          batch_size: batchSize,
          wc_category_parent: parentId,
          wc_category_child: childId,
        },
        (resp) => {
          if (resp.success) {
            retryCount = 0;
            const data = resp.data || {};
            const processed = data.processed || 0;
            const total = data.total || 0;

            addLogEntry(
              `Lote procesado: ${processed} productos (offset ${offset})`,
              "info"
            );

            // actualizar barra de progreso
            const percentage = Math.round(
              (Math.min(offset + batchSize, total) / total) * 100
            );
            $(".marpico-progress-fill").css("width", percentage + "%");
            $(".marpico-progress-text").text(
              `${percentage}% - Procesados ${Math.min(
                offset + batchSize,
                total
              )} de ${total} productos`
            );

            offset += batchSize;

            if (offset < total) {
              setTimeout(() => {
                processBatch(); // siguiente lote con pequeña pausa
              }, 500);
            } else {
              syncInProgress = false;
              const elapsed = formatElapsedTime(syncStartTime);
              const message = `Categoría ${categoryId} sincronizada exitosamente en ${elapsed}`;
              addLogEntry(message, "success");
              $("#api-sync-status").html(
                `<span class="beststock-log-success">✓ ${message}</span>`
              );
              $("#beststock-category").val("");
              $("#beststock-subcategory")
                .html('<option value="">Selecciona subcategoría</option>')
                .prop("disabled", true);
              $("#wc-category").val("");
              $("#wc-category-child").remove();
              updateStats();
              btn.prop("disabled", false).text("Sincronizar Categoría");
            }
          } else {
            handleBatchError(resp.data || "Error desconocido");
          }
        }
      ).fail(() => {
        handleBatchError("Error de conexión AJAX");
      });
    }

    function handleBatchError(errorMsg) {
      retryCount++;

      if (retryCount <= maxRetries) {
        const retryDelay = 5000 * retryCount; // 5s, 10s, 15s...
        addLogEntry(
          `Error: ${errorMsg}. Reintentando lote ${retryCount}/${maxRetries} en ${
            retryDelay / 1000
          }s`,
          "error"
        );

        setTimeout(() => {
          processBatch();
        }, retryDelay);
      } else {
        syncInProgress = false;
        const elapsed = formatElapsedTime(syncStartTime);
        addLogEntry(
          `Error persistente: ${errorMsg}. Lote detenido tras ${retryCount} intentos. Procesados hasta offset ${offset} en ${elapsed}`,
          "error"
        );
        btn.prop("disabled", false).text("Sincronizar Categoría");
      }
    }

    processBatch(); // arrancamos con el primer lote
  });

  // --- Cargar subcategorías dinámicamente ---
  $("#wc-category").on("change", function () {
    const parentId = $(this).val();
    //$("#wc-category-child").remove(); // limpiamos hijos previos
    console.log("Padre seleccionado:", parentId);

    if (!parentId) {
      $("#child-category-wrapper").hide(); // esconder bloque completo
      $("#wc-category-child").empty();
      return;
    }

    // Mostrar estado de carga
    $("#wc-category-child")
      .html('<option value="">Cargando subcategorías...</option>')
      .prop("disabled", true);
    $("#child-category-wrapper").show();

    $.post(
      marpico_ajax.ajax_url,
      {
        action: "get_child_categories",
        security: marpico_ajax.nonce,
        parent_id: parentId,
      },
      function (resp) {
        console.log("Respuesta hijos:", resp);

        if (resp.success && resp.data.length > 0) {
          let options = '<option value="">Selecciona subcategoría</option>';
          resp.data.forEach((cat) => {
            options += `<option value="${cat.id}">${cat.name}</option>`;
          });
          $("#wc-category-child").html(options).prop("disabled", false);
          $("#child-category-wrapper").show();
        } else {
          $("#child-category-wrapper").hide();
          $("#wc-category-child").empty();
        }
      }
    ).fail(function (xhr) {
      console.error("Error AJAX hijos:", xhr);
    });
  });
});
