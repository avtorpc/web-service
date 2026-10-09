(() => {
    document.querySelectorAll('[data-publication-action]').forEach(button => {
        button.addEventListener('click', async () => {
            if (button.disabled) return;
            const message = button.parentElement.querySelector('[role="status"]');
            button.disabled = true;
            message.textContent = 'Обновляем публикацию…';
            message.dataset.error = 'false';
            try {
                const response = await fetch('/cabinet/vacancies/' + encodeURIComponent(button.dataset.id) + '/' + button.dataset.publicationAction, {
                    method: 'POST', credentials: 'same-origin',
                    headers: {'Content-Type':'application/json','X-CSRF-Token':document.querySelector('meta[name="registration-csrf"]').content},
                    body: JSON.stringify({version:Number(button.dataset.version)})
                });
                if (response.status === 401) { location.assign('/?login=1'); return; }
                const result = await response.json();
                if (!response.ok || !result.success) throw new Error(result.error?.message || 'Не удалось изменить публикацию.');
                location.hash = 'vacancies';
                location.reload();
            } catch (error) {
                message.textContent = error.message;
                message.dataset.error = 'true';
                button.disabled = false;
            }
        });
    });
    document.querySelectorAll('[data-assignment-stub]').forEach(button => {
        button.addEventListener('click', () => {
            button.parentElement.querySelector('.assignment-stub-status').hidden = false;
        });
    });
    const search = document.querySelector('#vacancySearch');
    search?.addEventListener('input', () => {
        const query = search.value.trim().toLocaleLowerCase('ru');
        let visible = 0;
        document.querySelectorAll('[data-vacancy-search]').forEach(card => {
            card.hidden = !card.dataset.vacancySearch.toLocaleLowerCase('ru').includes(query);
            if (!card.hidden) visible++;
        });
        document.querySelector('#vacancySearchEmpty').hidden = visible > 0;
    });
    const form = document.querySelector('#vacancyForm');
    if (!form) return;
    const csrf = document.querySelector('meta[name="registration-csrf"]').content;
    const salary = form.elements.salary_negotiable;
    function salaryState() {
        form.elements.salary_from.disabled = salary.checked;
        form.elements.salary_to.disabled = salary.checked;
    }
    salary.addEventListener('change', salaryState);
    salaryState();
    form.addEventListener('submit', async event => {
        event.preventDefault();
        const button = form.querySelector('button[type="submit"]');
        if (button.disabled) return;
        const message = document.querySelector('#vacancyStatus');
        button.disabled = true;
        message.dataset.error = 'false';
        message.textContent = 'Сохраняем черновик…';
        try {
            if (!form.dataset.id) form.dataset.id = crypto.randomUUID();
            const fields = Object.fromEntries(new FormData(form));
            const title = fields.title;
            delete fields.title;
            fields.salary_negotiable = salary.checked;
            fields.salary_from ??= '';
            fields.salary_to ??= '';
            const response = await fetch('/cabinet/vacancies', {
                method: 'POST', credentials: 'same-origin',
                headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrf},
                body: JSON.stringify({id: form.dataset.id, version: Number(form.dataset.version), title, content: fields})
            });
            if (response.status === 401) { location.assign('/?login=1'); return; }
            const result = await response.json();
            if (!response.ok || !result.success) throw new Error(result.error?.message || 'Не удалось сохранить вакансию. Попробуйте позже.');
            form.dataset.version = result.data.version;
            form.elements.salary_from.value = result.data.content.salary_from;
            form.elements.salary_to.value = result.data.content.salary_to;
            history.replaceState(null, '', '/cabinet/vacancies/' + encodeURIComponent(result.data.id) + '/edit');
            message.textContent = 'Черновик сохранён. Он доступен в разделе «Мои вакансии» вашего кабинета.';
        } catch (error) {
            message.dataset.error = 'true';
            message.textContent = error.message;
        } finally {
            button.disabled = false;
        }
    });
})();
