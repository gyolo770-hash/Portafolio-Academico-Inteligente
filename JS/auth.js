document.addEventListener("DOMContentLoaded", () => {
    const forms = document.querySelectorAll(".needs-validation");

    forms.forEach((form) => {
        form.addEventListener("submit", (event) => {
            const password = form.querySelector("#password");
            const passwordConfirm = form.querySelector("#passwordConfirm");

            if (password && passwordConfirm && password.value !== passwordConfirm.value) {
                passwordConfirm.setCustomValidity("Las contraseñas no coinciden.");
            } else if (passwordConfirm) {
                passwordConfirm.setCustomValidity("");
            }

            form.classList.add("was-validated");

            if (!form.checkValidity()) {
                event.preventDefault();
                event.stopPropagation();
            }
        });
    });

    document.querySelectorAll("[data-avatar-preview]").forEach((input) => {
        const previewSelector = input.dataset.avatarPreview;
        const fallbackSelector = input.dataset.avatarFallback;
        const preview = previewSelector ? document.querySelector(previewSelector) : null;
        const fallback = fallbackSelector ? document.querySelector(fallbackSelector) : null;

        if (!preview) {
            return;
        }

        input.addEventListener("change", () => {
            const file = input.files && input.files[0];

            if (!file) {
                return;
            }

            const allowedTypes = ["image/jpeg", "image/png", "image/webp"];
            if (!allowedTypes.includes(file.type)) {
                input.setCustomValidity("Solo se permiten imágenes JPG, PNG o WebP.");
                input.reportValidity();
                return;
            }

            input.setCustomValidity("");

            const reader = new FileReader();
            reader.addEventListener("load", () => {
                preview.src = reader.result;
                preview.classList.remove("d-none");
                if (fallback) {
                    fallback.classList.add("d-none");
                }
            });
            reader.readAsDataURL(file);
        });
    });

    document.querySelectorAll("[data-file-preview]").forEach((input) => {
        const targetSelector = input.dataset.filePreview;
        const target = targetSelector ? document.querySelector(targetSelector) : null;

        if (!target) {
            return;
        }

        input.addEventListener("change", () => {
            const file = input.files && input.files[0];
            if (!file) {
                return;
            }

            target.textContent = file.name;
            target.classList.remove("text-muted");
        });
    });
});
