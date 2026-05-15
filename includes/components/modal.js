document.addEventListener("click", (e) => {
    // OPEN
    if (e.target.closest("[data-open]")) {
        const id = e.target.closest("[data-open]").dataset.open;
        document.getElementById(id)?.classList.add("active");
    }

    // CLOSE button
    if (e.target.closest("[data-close]")) {
        const id = e.target.closest("[data-close]").dataset.close;
        document.getElementById(id)?.classList.remove("active");
    }

    // CLICK OUTSIDE MODAL
    document.querySelectorAll(".modal").forEach(modal => {
        modal.addEventListener("click", (ev) => {
            if (ev.target === modal) {
                modal.classList.remove("active");
            }
        });
    });
});