
document.querySelectorAll('.delete-form button').forEach(button => {
    button.addEventListener('click', function (e) {
        const form = this.closest('form');

        Swal.fire({
            title: 'Supprimer ce produit ?',
            text: "Cette action est irréversible !",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#e3342f',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Oui, supprimer',
            cancelButtonText: 'Annuler'
        }).then((result) => {
            if (result.isConfirmed) {
                form.submit();
            }
        });
    });
});