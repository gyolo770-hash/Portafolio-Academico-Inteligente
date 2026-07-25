document.addEventListener("DOMContentLoaded", () => {
    const colorBlindToggles = document.querySelectorAll("[data-color-blind-toggle]");

    colorBlindToggles.forEach((toggle) => {
        toggle.addEventListener("change", () => {
            document.documentElement.classList.add("theme-transition");
            document.body.classList.toggle("color-blind-mode", toggle.checked);
            document.body.classList.toggle("deuteranopia-mode", toggle.checked);

            window.setTimeout(() => {
                document.documentElement.classList.remove("theme-transition");
            }, 320);
        });
    });

    const toggles = document.querySelectorAll("[data-toggle-password]");

    toggles.forEach((toggle) => {
        toggle.addEventListener("click", () => {
            const target = document.querySelector(toggle.dataset.togglePassword);

            if (!target) {
                return;
            }

            const isPassword = target.getAttribute("type") === "password";
            target.setAttribute("type", isPassword ? "text" : "password");
            toggle.setAttribute("aria-label", isPassword ? "Ocultar contraseña" : "Mostrar contraseña");

            const icon = toggle.querySelector(".bi");
            if (icon) {
                icon.classList.toggle("bi-eye", !isPassword);
                icon.classList.toggle("bi-eye-slash", isPassword);
            }
        });
    });

    document.querySelectorAll("form[onsubmit*='confirm(']").forEach((form) => {
        const match = form.getAttribute("onsubmit")?.match(/confirm\('([^']*)'\)/);
        if (match) {
            form.removeAttribute("onsubmit");
            form.dataset.confirmMessage = match[1];
        }
    });

    const confirmDialog = document.getElementById("appConfirmDialog");
    const confirmMessage = document.getElementById("appConfirmMessage");
    const confirmAccept = document.getElementById("appConfirmAccept");
    const confirmCancel = document.getElementById("appConfirmCancel");
    let pendingForm = null;

    const closeConfirmDialog = () => {
        if (confirmDialog && typeof confirmDialog.close === "function") {
            confirmDialog.close();
        }
        pendingForm = null;
    };

    if (confirmDialog && confirmMessage && confirmAccept && confirmCancel) {
        document.querySelectorAll("form[data-confirm-message]").forEach((form) => {
            form.addEventListener("submit", (event) => {
                if (form.dataset.confirmed === "true") {
                    form.dataset.confirmed = "false";
                    return;
                }

                event.preventDefault();
                pendingForm = form;
                confirmMessage.textContent = form.dataset.confirmMessage || "¿Confirmas esta acción?";
                confirmDialog.showModal();
            });
        });

        confirmAccept.addEventListener("click", () => {
            if (pendingForm) {
                pendingForm.dataset.confirmed = "true";
                pendingForm.requestSubmit();
            }
            closeConfirmDialog();
        });

        confirmCancel.addEventListener("click", closeConfirmDialog);
        confirmDialog.addEventListener("cancel", closeConfirmDialog);
    }
});
