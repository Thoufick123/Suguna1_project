/**
 * FabriqPro - Frontend Application Logic & AJAX Handlers
 */

document.addEventListener('DOMContentLoaded', () => {
    initTheme();
    initToasts();
    initRegistrationForm();
    initPieceEntryForm();
    initLeadsPage();
    initLeaderboardPage();
    initModal();
});

// ===================================================================
// 1. THEME MANAGER (LOCKED TO DEFAULT DARK MODE)
// ===================================================================
function initTheme() {
    document.body.classList.remove('theme-light');
    document.body.classList.add('theme-dark');
    if (localStorage.getItem('fabriq_theme')) {
        localStorage.removeItem('fabriq_theme');
    }
}

// ===================================================================
// 2. TOAST NOTIFICATION SYSTEM
// ===================================================================
let toastContainer;
function initToasts() {
    toastContainer = document.getElementById('toastContainer');
}

function showToast(message, type = 'info', duration = 4000) {
    if (!toastContainer) return;

    const toast = document.createElement('div');
    toast.className = `toast ${type}`;

    let icon = 'fa-info-circle';
    if (type === 'success') icon = 'fa-circle-check';
    if (type === 'error') icon = 'fa-circle-exclamation';

    toast.innerHTML = `
        <i class="fa-solid ${icon}" style="font-size: 1.25rem;"></i>
        <div style="flex: 1; font-size: 0.9rem;">${message}</div>
    `;

    toastContainer.appendChild(toast);

    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transform = 'translateX(50px)';
        toast.style.transition = 'all 0.3s ease';
        setTimeout(() => toast.remove(), 300);
    }, duration);
}

// ===================================================================
// 3. WORKER REGISTRATION PAGE
// ===================================================================
function initRegistrationForm() {
    const form = document.getElementById('workerRegisterForm');
    const genIdBtn = document.getElementById('generateIdBtn');
    const uniqueIdInput = document.getElementById('uniqueIdInput');
    const skillTags = document.querySelectorAll('.skill-tag-pill');
    const extraSkillsInput = document.getElementById('extraSkillsInput');

    if (!form) return;

    // Auto-generate Unique ID based on selected Production Unit: PU001-1, PU001-2... / PU002-1, PU002-2...
    async function updateNextWorkerId() {
        if (!uniqueIdInput) return;
        const selectedUnitRadio = form.querySelector('input[name="unit"]:checked');
        const unitVal = selectedUnitRadio ? selectedUnitRadio.value : 'Unit 1';
        try {
            const res = await fetch(`api.php?action=get_next_worker_id&unit=${encodeURIComponent(unitVal)}`);
            const data = await res.json();
            if (data.success && data.next_id) {
                uniqueIdInput.value = data.next_id;
            }
        } catch (e) {
            console.error('Failed to get next worker ID:', e);
        }
    }

    // Switch ID prefix & next sequential number whenever user selects Unit 1 or Unit 2
    const unitRadios = form.querySelectorAll('input[name="unit"]');
    unitRadios.forEach(radio => {
        radio.addEventListener('change', () => {
            updateNextWorkerId();
        });
    });

    if (genIdBtn && uniqueIdInput) {
        genIdBtn.addEventListener('click', async () => {
            await updateNextWorkerId();
            if (typeof showToast === 'function') {
                showToast(`Generated ID: ${uniqueIdInput.value}`, 'info');
            }
        });
    }

    // Skill Tag Click Handler
    if (skillTags.length > 0 && extraSkillsInput) {
        skillTags.forEach(tag => {
            tag.addEventListener('click', () => {
                const skill = tag.dataset.skill;
                let current = extraSkillsInput.value.split(',').map(s => s.trim()).filter(Boolean);
                if (!current.includes(skill)) {
                    current.push(skill);
                    extraSkillsInput.value = current.join(', ');
                    tag.classList.add('active');
                } else {
                    current = current.filter(s => s !== skill);
                    extraSkillsInput.value = current.join(', ');
                    tag.classList.remove('active');
                }
            });
        });
    }

    // Live Password Validation & Autofill Watcher
    const workerPassInput = document.getElementById('workerPasswordInput');
    if (workerPassInput) {
        ['input', 'change', 'keyup', 'paste', 'focus'].forEach(evt => {
            workerPassInput.addEventListener(evt, () => {
                validateWorkerPassword(workerPassInput.value);
            });
        });
        validateWorkerPassword(workerPassInput.value);
        setTimeout(() => validateWorkerPassword(workerPassInput.value), 200);
        setTimeout(() => validateWorkerPassword(workerPassInput.value), 600);
    }

    // Live Mobile Number Validation & Duplicate Detection
    const mobileInput = document.getElementById('mobileInput');
    const altMobileInput = document.getElementById('altMobileInput');
    const mobileFeedback = document.getElementById('mobileFeedback');
    const altMobileFeedback = document.getElementById('altMobileFeedback');
    let isMobileDuplicate = false;
    let mobileCheckTimeout = null;
    let altMobileCheckTimeout = null;

    // Instantly strip non-digit characters and truncate to 10 digits as user types or pastes
    [mobileInput, altMobileInput].forEach(inp => {
        if (!inp) return;
        inp.addEventListener('input', function() {
            this.value = this.value.replace(/\D/g, '').slice(0, 10);
        });
        inp.addEventListener('paste', function(e) {
            setTimeout(() => {
                this.value = this.value.replace(/\D/g, '').slice(0, 10);
            }, 0);
        });
    });

    async function checkMobileAvailability(mobile) {
        if (!mobileFeedback) return;
        const cleanDigits = (mobile || '').replace(/\D/g, '').slice(0, 10);
        if (mobileInput && mobileInput.value !== cleanDigits) {
            mobileInput.value = cleanDigits;
        }

        if (!cleanDigits) {
            mobileFeedback.style.display = 'none';
            mobileFeedback.innerHTML = '';
            if (mobileInput) {
                mobileInput.style.borderColor = '';
                mobileInput.style.boxShadow = '';
                mobileInput.setCustomValidity('');
            }
            isMobileDuplicate = false;
            return;
        }

        // Must start with 6, 7, 8, or 9
        if (!/^[6-9]/.test(cleanDigits)) {
            mobileFeedback.style.display = 'flex';
            mobileFeedback.style.color = '#f43f5e';
            mobileFeedback.innerHTML = '<i class="fa-solid fa-circle-exclamation text-rose"></i> <span>Mobile number must start with 6, 7, 8, or 9</span>';
            if (mobileInput) {
                mobileInput.style.borderColor = '#f43f5e';
                mobileInput.style.boxShadow = '0 0 0 3px rgba(244, 63, 94, 0.2)';
                mobileInput.setCustomValidity('Must start with 6, 7, 8, or 9');
            }
            isMobileDuplicate = false;
            return;
        }

        if (cleanDigits.length < 10) {
            mobileFeedback.style.display = 'flex';
            mobileFeedback.style.color = 'var(--text-muted, #94a3b8)';
            mobileFeedback.innerHTML = `<i class="fa-solid fa-circle-info"></i> <span>Enter 10 digits (${cleanDigits.length}/10 entered)</span>`;
            if (mobileInput) {
                mobileInput.style.borderColor = '';
                mobileInput.style.boxShadow = '';
                mobileInput.setCustomValidity('Please enter exactly 10 digits');
            }
            isMobileDuplicate = false;
            return;
        }

        try {
            const res = await fetch(`api.php?action=check_mobile&mobile=${encodeURIComponent(cleanDigits)}`);
            const data = await res.json();

            if (data.exists) {
                isMobileDuplicate = true;
                mobileFeedback.style.display = 'flex';
                mobileFeedback.style.color = '#f43f5e';
                const ownerInfo = data.worker_name ? ` (registered to ${escapeHtml(data.worker_name)}${data.unique_id ? ` - ${escapeHtml(data.unique_id)}` : ''})` : '';
                mobileFeedback.innerHTML = `<i class="fa-solid fa-circle-exclamation text-rose"></i> <span>This mobile number already exists${ownerInfo}.</span>`;
                if (mobileInput) {
                    mobileInput.style.borderColor = '#f43f5e';
                    mobileInput.style.boxShadow = '0 0 0 3px rgba(244, 63, 94, 0.2)';
                    mobileInput.setCustomValidity('This mobile number already exists.');
                }
            } else {
                isMobileDuplicate = false;
                mobileFeedback.style.display = 'flex';
                mobileFeedback.style.color = '#10b981';
                mobileFeedback.innerHTML = `<i class="fa-solid fa-circle-check text-emerald"></i> <span>Valid 10-digit mobile number (${cleanDigits})</span>`;
                if (mobileInput) {
                    mobileInput.style.borderColor = '#10b981';
                    mobileInput.style.boxShadow = '0 0 0 3px rgba(16, 185, 129, 0.2)';
                    mobileInput.setCustomValidity('');
                }
            }
        } catch (err) {
            console.error('Error checking mobile availability:', err);
        }
    }

    function checkAltMobileValidation() {
        if (!altMobileInput) return true;
        const cleanAlt = altMobileInput.value.replace(/\D/g, '').slice(0, 10);
        if (altMobileInput.value !== cleanAlt) {
            altMobileInput.value = cleanAlt;
        }

        if (!cleanAlt) {
            if (altMobileFeedback) {
                altMobileFeedback.style.display = 'none';
                altMobileFeedback.innerHTML = '';
            }
            altMobileInput.style.borderColor = '';
            altMobileInput.style.boxShadow = '';
            altMobileInput.setCustomValidity('');
            return true;
        }

        const cleanPrimary = mobileInput ? mobileInput.value.replace(/\D/g, '') : '';
        if (cleanAlt === cleanPrimary && cleanPrimary.length === 10) {
            if (altMobileFeedback) {
                altMobileFeedback.style.display = 'flex';
                altMobileFeedback.style.color = '#f43f5e';
                altMobileFeedback.innerHTML = '<i class="fa-solid fa-circle-exclamation text-rose"></i> <span>Alternative number cannot be same as primary</span>';
            }
            altMobileInput.style.borderColor = '#f43f5e';
            altMobileInput.style.boxShadow = '0 0 0 3px rgba(244, 63, 94, 0.2)';
            altMobileInput.setCustomValidity('Cannot be same as primary mobile');
            return false;
        }

        if (!/^[6-9]/.test(cleanAlt)) {
            if (altMobileFeedback) {
                altMobileFeedback.style.display = 'flex';
                altMobileFeedback.style.color = '#f43f5e';
                altMobileFeedback.innerHTML = '<i class="fa-solid fa-circle-exclamation text-rose"></i> <span>Must start with 6, 7, 8, or 9</span>';
            }
            altMobileInput.style.borderColor = '#f43f5e';
            altMobileInput.style.boxShadow = '0 0 0 3px rgba(244, 63, 94, 0.2)';
            altMobileInput.setCustomValidity('Must start with 6, 7, 8, or 9');
            return false;
        }

        if (cleanAlt.length < 10) {
            if (altMobileFeedback) {
                altMobileFeedback.style.display = 'flex';
                altMobileFeedback.style.color = 'var(--text-muted, #94a3b8)';
                altMobileFeedback.innerHTML = `<i class="fa-solid fa-circle-info"></i> <span>Enter 10 digits (${cleanAlt.length}/10 entered)</span>`;
            }
            altMobileInput.style.borderColor = '';
            altMobileInput.style.boxShadow = '';
            altMobileInput.setCustomValidity('Please enter 10 digits');
            return false;
        }

        if (altMobileFeedback) {
            altMobileFeedback.style.display = 'flex';
            altMobileFeedback.style.color = '#10b981';
            altMobileFeedback.innerHTML = `<i class="fa-solid fa-circle-check text-emerald"></i> <span>Valid 10-digit alternative number (${cleanAlt})</span>`;
        }
        altMobileInput.style.borderColor = '#10b981';
        altMobileInput.style.boxShadow = '0 0 0 3px rgba(16, 185, 129, 0.2)';
        altMobileInput.setCustomValidity('');
        return true;
    }

    if (mobileInput) {
        ['input', 'change', 'blur', 'paste'].forEach(evt => {
            mobileInput.addEventListener(evt, () => {
                clearTimeout(mobileCheckTimeout);
                mobileCheckTimeout = setTimeout(() => {
                    checkMobileAvailability(mobileInput.value);
                    if (altMobileInput && altMobileInput.value) checkAltMobileValidation();
                }, 200);
            });
        });
    }

    if (altMobileInput) {
        ['input', 'change', 'blur', 'paste'].forEach(evt => {
            altMobileInput.addEventListener(evt, () => {
                clearTimeout(altMobileCheckTimeout);
                altMobileCheckTimeout = setTimeout(() => {
                    checkAltMobileValidation();
                }, 200);
            });
        });
    }

    // Reset handler to clear feedback
    form.addEventListener('reset', () => {
        isMobileDuplicate = false;
        if (mobileFeedback) {
            mobileFeedback.style.display = 'none';
            mobileFeedback.innerHTML = '';
        }
        if (altMobileFeedback) {
            altMobileFeedback.style.display = 'none';
            altMobileFeedback.innerHTML = '';
        }
        if (mobileInput) {
            mobileInput.style.borderColor = '';
            mobileInput.style.boxShadow = '';
            mobileInput.setCustomValidity('');
        }
        if (altMobileInput) {
            altMobileInput.style.borderColor = '';
            altMobileInput.style.boxShadow = '';
            altMobileInput.setCustomValidity('');
        }
    });

    // Submit handler
    form.addEventListener('submit', async (e) => {
        e.preventDefault();

        const submitBtn = form.querySelector('button[type="submit"]');
        const originalBtnHtml = submitBtn.innerHTML;

        // Primary Mobile Validation (Strict 10 digits starting with 6-9)
        const cleanMob = (mobileInput ? mobileInput.value : '').replace(/\D/g, '');
        if (!/^[6-9]\d{9}$/.test(cleanMob)) {
            showToast('Please enter a valid 10-digit primary mobile number starting with 6, 7, 8, or 9.', 'error');
            if (mobileInput) {
                mobileInput.focus();
                mobileInput.style.borderColor = '#f43f5e';
                mobileInput.style.boxShadow = '0 0 0 3px rgba(244, 63, 94, 0.2)';
            }
            return;
        }

        // Alternative Mobile Validation (If provided, must be 10 digits starting with 6-9 and not duplicate of primary)
        if (altMobileInput && altMobileInput.value.trim() !== '') {
            const cleanAlt = altMobileInput.value.replace(/\D/g, '');
            if (!/^[6-9]\d{9}$/.test(cleanAlt)) {
                showToast('Please enter a valid 10-digit alternative mobile number starting with 6, 7, 8, or 9.', 'error');
                altMobileInput.focus();
                altMobileInput.style.borderColor = '#f43f5e';
                altMobileInput.style.boxShadow = '0 0 0 3px rgba(244, 63, 94, 0.2)';
                return;
            }
            if (cleanAlt === cleanMob) {
                showToast('Alternative mobile number cannot be the same as primary mobile number.', 'error');
                altMobileInput.focus();
                altMobileInput.style.borderColor = '#f43f5e';
                altMobileInput.style.boxShadow = '0 0 0 3px rgba(244, 63, 94, 0.2)';
                return;
            }
        }

        // Mobile duplicate validation check
        if (isMobileDuplicate) {
            showToast('This mobile number already exists. Please use a different mobile number.', 'error');
            if (mobileInput) {
                mobileInput.focus();
                mobileInput.style.borderColor = '#f43f5e';
                mobileInput.style.boxShadow = '0 0 0 3px rgba(244, 63, 94, 0.2)';
            }
            return;
        }

        // Client-side Password Validation
        const passInput = form.querySelector('input[name="password"]');
        const passVal = passInput ? passInput.value.trim() : '';

        if (!passVal || passVal.length < 4 || passVal.length > 6) {
            showToast('Password must be between 4 and 6 characters long.', 'error');
            if (passInput) passInput.focus();
            return;
        }

        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Registering...';

        const formData = new FormData(form);
        formData.append('action', 'register_worker');

        try {
            const res = await fetch('api.php', {
                method: 'POST',
                body: formData
            });
            const data = await res.json();

            if (data.success) {
                showToast(data.message, 'success', 5000);
                
                // Show action dialog or redirect option
                const actionBox = document.getElementById('registrationSuccessBox');
                if (actionBox) {
                    actionBox.style.display = 'block';
                    document.getElementById('newWorkerSuccessName').innerText = formData.get('name');
                    document.getElementById('newWorkerSuccessId').innerText = data.unique_id;
                    document.getElementById('newWorkerSuccessUnit').innerText = formData.get('unit');
                    const roleEl = document.getElementById('newWorkerSuccessRole');
                    if (roleEl) {
                        const sRole = formData.get('system_role') || data.system_role || 'operator';
                        roleEl.innerText = sRole.charAt(0).toUpperCase() + sRole.slice(1);
                    }
                    document.getElementById('quickLogLink').href = `daily_output.php?worker_id=${data.worker_id}`;
                    form.reset();
                    isMobileDuplicate = false;
                    if (mobileFeedback) mobileFeedback.style.display = 'none';
                    if (mobileInput) {
                        mobileInput.style.borderColor = '';
                        mobileInput.style.boxShadow = '';
                    }
                    setTimeout(updateNextWorkerId, 50);
                    window.scrollTo({ top: actionBox.offsetTop - 50, behavior: 'smooth' });
                } else {
                    setTimeout(() => {
                        window.location.href = `daily_output.php?worker_id=${data.worker_id}`;
                    }, 1200);
                }
            } else {
                showToast(data.message || 'Registration failed.', 'error');
                if (data.message && (data.message.toLowerCase().includes('mobile number already exists') || data.message.toLowerCase().includes('mobile number is already exist'))) {
                    isMobileDuplicate = true;
                    if (mobileInput) {
                        mobileInput.focus();
                        mobileInput.style.borderColor = '#f43f5e';
                        mobileInput.style.boxShadow = '0 0 0 3px rgba(244, 63, 94, 0.2)';
                        mobileInput.setCustomValidity('This mobile number already exists.');
                    }
                    if (mobileFeedback) {
                        mobileFeedback.style.display = 'flex';
                        mobileFeedback.style.color = '#f43f5e';
                        mobileFeedback.innerHTML = `<i class="fa-solid fa-circle-exclamation text-rose"></i> <span>${escapeHtml(data.message)}</span>`;
                    }
                }
            }
        } catch (err) {
            showToast('Network or server error during registration.', 'error');
            console.error(err);
        } finally {
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalBtnHtml;
        }
    });
}

// ===================================================================
// 4. PIECE COUNT ENTRY FORM
// ===================================================================
function initPieceEntryForm() {
    const form = document.getElementById('pieceEntryForm');
    const workerSelect = document.getElementById('workerSelect');
    const materialInput = document.getElementById('materialInput');
    const pieceCountInput = document.getElementById('pieceCountInput');
    const previewBox = document.getElementById('workerPreviewBox');
    const stepperBtns = document.querySelectorAll('.piece-stepper-btn');
    const materialPills = document.querySelectorAll('.material-tag-pill');

    if (!form) return;

    // Worker Selection Preview Update
    if (workerSelect) {
        workerSelect.addEventListener('change', () => {
            const selectedOpt = workerSelect.options[workerSelect.selectedIndex];
            if (selectedOpt && selectedOpt.value) {
                const name = selectedOpt.dataset.name;
                const unit = selectedOpt.dataset.unit;
                const role = selectedOpt.dataset.role;
                const totalPieces = selectedOpt.dataset.pieces || 0;
                const uniqueId = selectedOpt.dataset.unique;

                document.getElementById('previewWorkerName').innerText = name;
                document.getElementById('previewWorkerSub').innerText = `${uniqueId} • ${role}`;
                document.getElementById('previewWorkerUnit').innerHTML = `<span class="unit-badge ${unit === 'Unit 1' ? 'unit-1' : 'unit-2'}">${unit}</span>`;
                document.getElementById('previewWorkerPieces').innerText = `${parseInt(totalPieces).toLocaleString()} Pieces Logged`;
                document.getElementById('previewAvatar').innerText = name.charAt(0).toUpperCase();

                previewBox.classList.add('selected');
            } else {
                previewBox.classList.remove('selected');
                document.getElementById('previewWorkerName').innerText = 'No Worker Selected';
                document.getElementById('previewWorkerSub').innerText = 'Please choose a worker from the dropdown above';
                document.getElementById('previewWorkerUnit').innerHTML = '';
                document.getElementById('previewWorkerPieces').innerText = '0 Pieces';
            }
        });

        // Trigger change on load if preselected
        if (workerSelect.value) {
            workerSelect.dispatchEvent(new Event('change'));
        }

        // Unit Filtering for Piece Entry Worker Dropdown
        const peUnitBtns = document.querySelectorAll('.pe-unit-btn');
        if (peUnitBtns.length > 0) {
            const allPeOpts = Array.from(workerSelect.options).map(opt => ({
                value: opt.value,
                text: opt.text,
                name: opt.dataset.name,
                unit: opt.dataset.unit,
                role: opt.dataset.role,
                pieces: opt.dataset.pieces,
                unique: opt.dataset.unique
            }));

            peUnitBtns.forEach(btn => {
                btn.addEventListener('click', () => {
                    const unit = btn.dataset.unit;
                    peUnitBtns.forEach(b => {
                        b.classList.remove('active', 'active-cyan', 'active-pink', 'active-emerald');
                    });
                    btn.classList.add('active');
                    if (btn.dataset.color) btn.classList.add('active-' + btn.dataset.color);

                    const curVal = workerSelect.value;
                    workerSelect.innerHTML = '';
                    const filtered = allPeOpts.filter(o => !o.value || unit === 'all' || !unit || o.unit === unit);
                    let found = false;
                    filtered.forEach(o => {
                        const opt = document.createElement('option');
                        opt.value = o.value;
                        opt.text = o.text;
                        if (o.name) opt.dataset.name = o.name;
                        if (o.unit) opt.dataset.unit = o.unit;
                        if (o.role) opt.dataset.role = o.role;
                        if (o.pieces) opt.dataset.pieces = o.pieces;
                        if (o.unique) opt.dataset.unique = o.unique;
                        if (o.value && o.value === curVal) {
                            opt.selected = true;
                            found = true;
                        }
                        workerSelect.appendChild(opt);
                    });

                    if (!found && workerSelect.options.length > 1) {
                        workerSelect.selectedIndex = 1;
                    }
                    workerSelect.dispatchEvent(new Event('change'));
                });
            });
        }
    }

    // Material Dropdown Selection & Custom Option
    const materialSelect = document.getElementById('materialSelect');
    const customMaterialBox = document.getElementById('customMaterialBox');
    const customMaterialInput = document.getElementById('customMaterialInput');

    if (materialSelect) {
        materialSelect.addEventListener('change', () => {
            if (materialSelect.value === '__custom__') {
                if (customMaterialBox) customMaterialBox.style.display = 'block';
                if (customMaterialInput) customMaterialInput.focus();
            } else {
                if (customMaterialBox) customMaterialBox.style.display = 'none';
            }
        });
    }

    // Increment & Decrement Steppers
    const decBtn = document.getElementById('pieceDecBtn');
    const incBtn = document.getElementById('pieceIncBtn');
    const clearBtn = document.getElementById('pieceClearBtn');

    if (decBtn && pieceCountInput) {
        decBtn.addEventListener('click', () => {
            const current = parseInt(pieceCountInput.value) || 0;
            const next = Math.max(0, current - 1);
            pieceCountInput.value = next;
            pieceCountInput.dispatchEvent(new Event('input'));
            pieceCountInput.dispatchEvent(new Event('change'));
        });
    }

    if (incBtn && pieceCountInput) {
        incBtn.addEventListener('click', () => {
            const current = parseInt(pieceCountInput.value) || 0;
            const next = current + 1;
            pieceCountInput.value = next;
            pieceCountInput.dispatchEvent(new Event('input'));
            pieceCountInput.dispatchEvent(new Event('change'));
        });
    }

    if (clearBtn && pieceCountInput) {
        clearBtn.addEventListener('click', () => {
            pieceCountInput.value = 0;
            pieceCountInput.focus();
            pieceCountInput.dispatchEvent(new Event('input'));
            pieceCountInput.dispatchEvent(new Event('change'));
        });
    }

    // Dynamic Complete Button Label Updater
    const completeBtnLabel = document.getElementById('completeBtnLabel');
    const completeSubmitBtn = document.getElementById('completeSubmitBtn');
    const formResetBtn = document.getElementById('formResetBtn');

    function updateCompleteButton() {
        if (!completeBtnLabel || !pieceCountInput) return;
        const count = parseInt(pieceCountInput.value) || 0;
        if (count <= 0) {
            completeBtnLabel.innerHTML = 'Complete &amp; Save (0 Pieces)';
            if (completeSubmitBtn) {
                completeSubmitBtn.classList.remove('btn-ready-pulse');
                completeSubmitBtn.style.opacity = '0.75';
            }
        } else if (count === 1) {
            completeBtnLabel.innerHTML = 'Complete &amp; Save 1 Stitched Piece';
            if (completeSubmitBtn) {
                completeSubmitBtn.classList.add('btn-ready-pulse');
                completeSubmitBtn.style.opacity = '1';
            }
        } else {
            completeBtnLabel.innerHTML = `Complete &amp; Save ${count.toLocaleString()} Pieces`;
            if (completeSubmitBtn) {
                completeSubmitBtn.classList.add('btn-ready-pulse');
                completeSubmitBtn.style.opacity = '1';
            }
        }
    }

    if (pieceCountInput) {
        pieceCountInput.addEventListener('input', updateCompleteButton);
        pieceCountInput.addEventListener('change', updateCompleteButton);
        updateCompleteButton();
    }

    if (formResetBtn && pieceCountInput) {
        formResetBtn.addEventListener('click', () => {
            pieceCountInput.value = 0;
            updateCompleteButton();
            pieceCountInput.focus();
        });
    }

    // Quick Increment / Decrement Pills (+/- 1, 5, 10, 50, 100)
    if (stepperBtns.length > 0 && pieceCountInput) {
        stepperBtns.forEach(btn => {
            btn.addEventListener('click', () => {
                const step = parseInt(btn.dataset.step) || 0;
                const current = parseInt(pieceCountInput.value) || 0;
                const next = Math.max(0, current + step);
                pieceCountInput.value = next;
                updateCompleteButton();
                pieceCountInput.dispatchEvent(new Event('input'));
                pieceCountInput.dispatchEvent(new Event('change'));
            });
        });
    }

    // Submit piece entry on "Complete" click
    form.addEventListener('submit', async (e) => {
        e.preventDefault();

        const count = parseInt(pieceCountInput.value) || 0;
        if (count <= 0) {
            showToast('Please enter at least 1 stitched piece before clicking Complete.', 'error');
            pieceCountInput.focus();
            return;
        }

        // Handle Custom Material name if selected
        let materialValue = materialSelect ? materialSelect.value : '';
        if (materialValue === '__custom__') {
            materialValue = customMaterialInput ? customMaterialInput.value.trim() : '';
            if (!materialValue) {
                showToast('Please type your custom cloth/material name.', 'error');
                if (customMaterialInput) customMaterialInput.focus();
                return;
            }
        }

        if (!materialValue) {
            showToast('Please select a cloth/material from the dropdown.', 'error');
            return;
        }

        const submitBtn = form.querySelector('button[type="submit"]');
        const originalBtnHtml = submitBtn.innerHTML;
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving...';

        const formData = new FormData(form);
        formData.set('material_name', materialValue);
        formData.set('piece_count', count);
        formData.append('action', 'log_pieces');

        try {
            const res = await fetch('api.php', {
                method: 'POST',
                body: formData
            });
            const data = await res.json();

            if (data.success) {
                showToast(data.message, 'success', 5000);
                
                // Update selected worker's cached piece count in dropdown
                const selectedOpt = workerSelect.options[workerSelect.selectedIndex];
                if (selectedOpt) {
                    selectedOpt.dataset.pieces = data.new_total_pieces;
                    workerSelect.dispatchEvent(new Event('change'));
                }

                // Reset piece count & notes, preserve worker & material for fast bulk entry
                pieceCountInput.value = 0;
                updateCompleteButton();
                const notesInput = document.getElementById('notesInput');
                if (notesInput) notesInput.value = '';

                // Show success feedback banner
                const successBanner = document.getElementById('pieceSuccessBanner');
                if (successBanner) {
                    successBanner.style.display = 'block';
                    document.getElementById('loggedWorkerInfo').innerText = `${data.worker_name} (${data.unique_id})`;
                    document.getElementById('loggedCountInfo').innerText = `+${data.logged_pieces} pcs (${data.material_name})`;
                    document.getElementById('newTotalInfo').innerText = `New Total: ${data.new_total_pieces} pcs`;
                }
            } else {
                showToast(data.message || 'Failed to log piece count.', 'error');
            }
        } catch (err) {
            showToast('Network error while recording piece count.', 'error');
            console.error(err);
        } finally {
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalBtnHtml;
        }
    });
}

// ===================================================================
// 5. DASHBOARD LEADS PAGE (PIECE LOGS WITH MATERIAL BREAKDOWN)
// ===================================================================
let leadsData = [];
function initLeadsPage() {
    const leadsTableBody = document.getElementById('leadsTableBody');
    if (!leadsTableBody) return;

    const unitFilter = document.getElementById('leadUnitFilter');
    const materialFilter = document.getElementById('leadMaterialFilter');
    const searchInput = document.getElementById('leadSearchInput');
    const dateFilter = document.getElementById('leadDateFilter');
    const refreshBtn = document.getElementById('leadRefreshBtn');

    async function fetchLeads() {
        leadsTableBody.innerHTML = `<tr><td colspan="8" class="text-center" style="padding: 2.5rem;"><i class="fa-solid fa-spinner fa-spin fa-2x text-cyan"></i><p class="text-muted mt-4">Loading piece leads...</p></td></tr>`;

        const params = new URLSearchParams();
        params.append('action', 'get_leads');
        if (unitFilter && unitFilter.value) params.append('unit', unitFilter.value);
        if (materialFilter && materialFilter.value) params.append('material', materialFilter.value);
        if (searchInput && searchInput.value) params.append('search', searchInput.value);
        if (dateFilter && dateFilter.value) params.append('date', dateFilter.value);

        try {
            const res = await fetch(`api.php?${params.toString()}`);
            const json = await res.json();
            if (json.success) {
                leadsData = json.data;
                renderLeadsTable(leadsData);
                updateLeadStats(leadsData);
            }
        } catch (err) {
            leadsTableBody.innerHTML = `<tr><td colspan="8" class="text-center text-rose" style="padding: 2rem;">Error loading leads.</td></tr>`;
        }
    }

    function renderLeadsTable(data) {
        if (!data || data.length === 0) {
            leadsTableBody.innerHTML = `
                <tr>
                    <td colspan="8" class="text-center" style="padding: 3rem;">
                        <i class="fa-solid fa-box-open fa-3x text-muted" style="opacity: 0.5;"></i>
                        <p class="text-muted mt-4 font-bold">No Piece Count Leads Found</p>
                        <p class="text-muted" style="font-size: 0.85rem;">Try adjusting your filters or log new pieces in the Piece Entry form.</p>
                    </td>
                </tr>
            `;
            return;
        }

        let html = '';
        data.forEach((lead, idx) => {
            const unitClass = lead.unit === 'Unit 1' ? 'unit-1' : 'unit-2';
            html += `
                <tr>
                    <td class="text-muted font-bold">#${idx + 1}</td>
                    <td>
                        <div class="worker-meta-cell">
                            <div class="worker-avatar">${lead.worker_name.charAt(0)}</div>
                            <div>
                                <a href="javascript:void(0)" onclick="openWorkerModal(${lead.worker_id})" class="worker-meta-name" style="text-decoration:none;">
                                    ${escapeHtml(lead.worker_name)}
                                </a>
                                <span class="worker-meta-sub">${escapeHtml(lead.unique_id)} • ${escapeHtml(lead.role)}</span>
                            </div>
                        </div>
                    </td>
                    <td>
                        <span class="unit-badge ${unitClass}">${escapeHtml(lead.unit)}</span>
                    </td>
                    <td>
                        <span class="material-badge">
                            <i class="fa-solid fa-layer-group"></i> ${escapeHtml(lead.material_name)}
                        </span>
                    </td>
                    <td>
                        <span class="piece-count-highlight">+${parseInt(lead.piece_count).toLocaleString()} pcs</span>
                    </td>
                    <td>
                        <div>
                            <strong>${lead.work_date}</strong>
                            <div class="text-muted" style="font-size: 0.75rem;">${escapeHtml(lead.shift)}</div>
                        </div>
                    </td>
                    <td>
                        <strong>${parseInt(lead.worker_cumulative_pieces).toLocaleString()}</strong>
                        <span class="text-muted" style="font-size: 0.75rem;"> total</span>
                    </td>
                    <td>
                        <button class="btn btn-outline btn-sm" onclick="openWorkerModal(${lead.worker_id})" title="View Worker Details">
                            <i class="fa-solid fa-eye"></i> Details
                        </button>
                    </td>
                </tr>
            `;
        });
        leadsTableBody.innerHTML = html;
    }

    function updateLeadStats(data) {
        const totalLogged = data.reduce((sum, d) => sum + parseInt(d.piece_count), 0);
        const uniqueMaterials = new Set(data.map(d => d.material_name)).size;
        const totalEntries = data.length;

        const totalPcsEl = document.getElementById('leadTotalPiecesStat');
        const totalMatEl = document.getElementById('leadTotalMaterialsStat');
        const totalEntriesEl = document.getElementById('leadTotalEntriesStat');

        if (totalPcsEl) totalPcsEl.innerText = totalLogged.toLocaleString();
        if (totalMatEl) totalMatEl.innerText = uniqueMaterials;
        if (totalEntriesEl) totalEntriesEl.innerText = totalEntries;
    }

    // Attach filter listeners
    if (unitFilter) unitFilter.addEventListener('change', fetchLeads);
    if (materialFilter) materialFilter.addEventListener('change', fetchLeads);
    if (dateFilter) dateFilter.addEventListener('change', fetchLeads);
    if (refreshBtn) refreshBtn.addEventListener('click', fetchLeads);

    if (searchInput) {
        let timer;
        searchInput.addEventListener('input', () => {
            clearTimeout(timer);
            timer = setTimeout(fetchLeads, 300);
        });
    }

    // Initial load
    fetchLeads();
}

// ===================================================================
// 6. LEADERBOARD PAGE (WORKERS RANKING & COMPETITION)
// ===================================================================
function initLeaderboardPage() {
    const leaderboardTableBody = document.getElementById('leaderboardTableBody');
    if (!leaderboardTableBody) return;

    const unitFilter = document.getElementById('lbUnitFilter');
    const materialFilter = document.getElementById('lbMaterialFilter');
    const sortFilter = document.getElementById('lbSortFilter');
    const searchInput = document.getElementById('lbSearchInput');

    async function fetchLeaderboard() {
        const params = new URLSearchParams();
        params.append('action', 'get_workers');
        if (unitFilter && unitFilter.value) params.append('unit', unitFilter.value);
        if (materialFilter && materialFilter.value) params.append('material', materialFilter.value);
        if (sortFilter && sortFilter.value) params.append('sort', sortFilter.value);
        if (searchInput && searchInput.value) params.append('search', searchInput.value);

        try {
            const res = await fetch(`api.php?${params.toString()}`);
            const json = await res.json();
            if (json.success) {
                renderPodium(json.data, materialFilter ? materialFilter.value : '');
                renderLeaderboardTable(json.data, materialFilter ? materialFilter.value : '');
            }
            if (typeof window.refreshCategorySlider === 'function') {
                window.refreshCategorySlider();
            }
        } catch (err) {
            console.error(err);
        }
    }

    function renderPodium(workers, activeMaterial) {
        const podium1 = document.getElementById('podiumRank1');
        const podium2 = document.getElementById('podiumRank2');
        const podium3 = document.getElementById('podiumRank3');

        if (!podium1 || !podium2 || !podium3) return;

        const w1 = workers[0];
        const w2 = workers[1];
        const w3 = workers[2];

        fillPodiumCard(podium1, w1, 1, activeMaterial);
        fillPodiumCard(podium2, w2, 2, activeMaterial);
        fillPodiumCard(podium3, w3, 3, activeMaterial);
    }

    function fillPodiumCard(el, worker, rank, activeMaterial) {
        if (!worker) {
            el.innerHTML = `<div class="text-muted text-center" style="padding: 2rem;">No Rank #${rank}</div>`;
            return;
        }

        const crown = rank === 1 ? '<div class="podium-crown"><i class="fa-solid fa-crown"></i></div>' : '';
        const unitClass = worker.unit === 'Unit 1' ? 'unit-1' : 'unit-2';

        const displayedScore = activeMaterial 
            ? parseInt(worker.material_pieces || 0).toLocaleString() 
            : parseInt(worker.total_pieces || 0).toLocaleString();

        const scoreLabel = activeMaterial 
            ? `<div class="stat-label"><i class="fa-solid fa-layer-group text-cyan"></i> ${escapeHtml(activeMaterial)}</div>` 
            : '<div class="stat-label">Total Pieces</div>';

        const extraSub = activeMaterial 
            ? `<div class="text-muted" style="font-size:0.75rem; margin-top:0.25rem;">Lifetime: ${parseInt(worker.total_pieces).toLocaleString()} pcs</div>` 
            : '';

        el.setAttribute('onclick', `openWorkerModal(${worker.id})`);
        el.style.cursor = 'pointer';
        el.setAttribute('title', `Click to view ${escapeHtml(worker.name)}'s profile`);

        el.innerHTML = `
            ${crown}
            <div class="podium-avatar-wrapper">
                <div class="podium-avatar">${worker.name.charAt(0)}</div>
                <div class="podium-rank-badge">#${rank}</div>
            </div>
            <h3 class="podium-name" title="${escapeHtml(worker.name)}">${escapeHtml(worker.name)}</h3>
            <p class="podium-role" title="${escapeHtml(worker.unique_id)} • ${escapeHtml(worker.role)}">${escapeHtml(worker.unique_id)} • ${escapeHtml(worker.role)}</p>
            <div class="podium-unit-tag">
                <span class="unit-badge ${unitClass}">${escapeHtml(worker.unit)}</span>
            </div>
            <div class="podium-score">
                ${scoreLabel}
                <div class="podium-pieces">${displayedScore} <span class="podium-pcs-unit">pcs</span></div>
                ${extraSub}
            </div>
            <button class="btn btn-outline btn-sm mt-3 podium-profile-btn" style="width: 100%;" onclick="event.stopPropagation(); openWorkerModal(${worker.id});">
                <i class="fa-solid fa-chart-pie"></i> <span class="podium-btn-text">Profile</span>
            </button>
        `;
    }

    function renderLeaderboardTable(workers, activeMaterial) {
        if (!workers || workers.length === 0) {
            leaderboardTableBody.innerHTML = `<tr><td colspan="7" class="text-center text-muted" style="padding: 2.5rem;">No workers found matching criteria.</td></tr>`;
            return;
        }

        let html = '';
        workers.forEach((w, idx) => {
            const rank = idx + 1;
            let rankBadgeClass = '';
            if (rank === 1) rankBadgeClass = 'rank-1-badge';
            else if (rank === 2) rankBadgeClass = 'rank-2-badge';
            else if (rank === 3) rankBadgeClass = 'rank-3-badge';

            const unitClass = w.unit === 'Unit 1' ? 'unit-1' : 'unit-2';

            const outputCellHtml = activeMaterial
                ? `
                    <div>
                        <span class="piece-count-highlight" style="font-size:1.15rem;">${parseInt(w.material_pieces || 0).toLocaleString()} pcs</span>
                        <div class="text-muted" style="font-size:0.75rem; margin-top:0.15rem;">
                            <i class="fa-solid fa-layer-group text-cyan"></i> ${escapeHtml(activeMaterial)} • Total: ${parseInt(w.total_pieces).toLocaleString()}
                        </div>
                    </div>
                  `
                : `
                    <span class="piece-count-highlight">${parseInt(w.total_pieces).toLocaleString()} pcs</span>
                  `;

            html += `
                <tr>
                    <td class="table-rank ${rankBadgeClass}">
                        ${rank === 1 ? '🥇' : rank === 2 ? '🥈' : rank === 3 ? '🥉' : '#' + rank}
                    </td>
                    <td>
                        <div class="worker-meta-cell">
                            <div class="worker-avatar">${w.name.charAt(0)}</div>
                            <div>
                                <a href="javascript:void(0)" onclick="openWorkerModal(${w.id})" class="worker-meta-name" style="text-decoration:none;">
                                    ${escapeHtml(w.name)}
                                </a>
                                <span class="worker-meta-sub">${escapeHtml(w.unique_id)} • ${escapeHtml(w.role)}</span>
                            </div>
                        </div>
                    </td>
                    <td>
                        <span class="unit-badge ${unitClass}">${escapeHtml(w.unit)}</span>
                    </td>
                    <td>
                        <strong>${w.experience_years}</strong> yrs
                    </td>
                    <td>
                        ${outputCellHtml}
                    </td>
                    <td>
                        <span class="text-muted">${escapeHtml(w.mobile_num)}</span>
                    </td>
                    <td>
                        <button class="btn btn-outline btn-sm" onclick="openWorkerModal(${w.id})" title="View Details">
                            <i class="fa-solid fa-chart-simple"></i> Details
                        </button>
                    </td>
                </tr>
            `;
        });
        leaderboardTableBody.innerHTML = html;
    }

    if (unitFilter) {
        unitFilter.addEventListener('change', () => {
            fetchLeaderboard();
            if (typeof window.switchUnitSlide === 'function') {
                const val = unitFilter.value;
                if (val === 'Unit 1') window.switchUnitSlide(1);
                else if (val === 'Unit 2') window.switchUnitSlide(2);
                else window.switchUnitSlide(0);
            }
        });
    }
    if (materialFilter) materialFilter.addEventListener('change', fetchLeaderboard);
    if (sortFilter) sortFilter.addEventListener('change', fetchLeaderboard);
    if (searchInput) {
        let timer;
        searchInput.addEventListener('input', () => {
            clearTimeout(timer);
            timer = setTimeout(fetchLeaderboard, 300);
        });
    }

    fetchLeaderboard();

    // Live tracking ticker: periodically refresh podium, ranking table & category production slider
    setInterval(() => {
        if (document.hidden || !document.getElementById('leaderboardTableBody')) return;
        fetchLeaderboard();
    }, 12000);
}

// ===================================================================
// 7. WORKER DETAILS MODAL DRAWER
// ===================================================================
function initModal() {
    const modal = document.getElementById('workerModal');
    const closeBtn = document.getElementById('closeModalBtn');

    if (modal && closeBtn) {
        closeBtn.addEventListener('click', () => modal.classList.remove('open'));
        modal.addEventListener('click', (e) => {
            if (e.target === modal) modal.classList.remove('open');
        });
    }
}

window.openWorkerModal = async function(workerId) {
    const modal = document.getElementById('workerModal');
    const modalBody = document.getElementById('modalBody');
    if (!modal || !modalBody) return;

    modal.classList.add('open');
    modalBody.innerHTML = `<div class="text-center" style="padding: 2.5rem;"><i class="fa-solid fa-spinner fa-spin fa-2x text-cyan"></i><p class="text-muted mt-4">Loading worker profile...</p></div>`;

    try {
        const res = await fetch(`api.php?action=get_worker_details&id=${workerId}`);
        const data = await res.json();

        if (data.success) {
            const w = data.worker;
            const materials = data.materials_breakdown;
            const logs = data.logs;

            document.getElementById('modalWorkerName').innerText = w.name;
            document.getElementById('modalWorkerSub').innerText = `${w.unique_id} • ${w.role} • ${w.unit}`;
            document.getElementById('modalAvatar').innerText = w.name.charAt(0).toUpperCase();

            let materialsHtml = '';
            if (materials && materials.length > 0) {
                materials.forEach(m => {
                    materialsHtml += `
                        <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.6rem 0; border-bottom: 1px solid var(--border-color);">
                            <span class="material-badge"><i class="fa-solid fa-cube"></i> ${escapeHtml(m.material_name)}</span>
                            <div>
                                <strong class="text-emerald">${parseInt(m.total_pieces).toLocaleString()} pcs</strong>
                                <span class="text-muted" style="font-size: 0.75rem;"> (${m.log_count} entries)</span>
                            </div>
                        </div>
                    `;
                });
            } else {
                materialsHtml = '<p class="text-muted">No material pieces logged yet.</p>';
            }

            let logsHtml = '';
            if (logs && logs.length > 0) {
                logs.slice(0, 5).forEach(l => {
                    logsHtml += `
                        <div style="font-size: 0.85rem; padding: 0.5rem 0; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between;">
                            <div>
                                <strong>${l.work_date}</strong> - ${escapeHtml(l.material_name)} (${escapeHtml(l.shift)})
                                ${l.notes ? `<div class="text-muted" style="font-size: 0.75rem;">Note: ${escapeHtml(l.notes)}</div>` : ''}
                            </div>
                            <span class="text-emerald font-bold">+${l.piece_count} pcs</span>
                        </div>
                    `;
                });
            } else {
                logsHtml = '<p class="text-muted">No logs recorded.</p>';
            }

            modalBody.innerHTML = `
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1.5rem;">
                    <div style="background: rgba(255,255,255,0.03); padding: 1rem; border-radius: var(--radius-md); border: 1px solid var(--border-color);">
                        <div class="stat-label">Total Pieces</div>
                        <div class="stat-value text-emerald">${parseInt(w.total_pieces).toLocaleString()}</div>
                        <div class="stat-sub">Leaderboard Rank #${w.rank}</div>
                    </div>
                    <div style="background: rgba(255,255,255,0.03); padding: 1rem; border-radius: var(--radius-md); border: 1px solid var(--border-color);">
                        <div class="stat-label">Experience & Age</div>
                        <div class="stat-value">${w.experience_years} yrs</div>
                        <div class="stat-sub">Age: ${w.age} years</div>
                    </div>
                </div>

                <div style="margin-bottom: 1.25rem;">
                    <h4 style="font-size: 0.9rem; margin-bottom: 0.5rem; text-transform: uppercase; color: var(--text-secondary); letter-spacing: 0.5px;">Contact & Address</h4>
                    <p style="font-size: 0.9rem;"><strong>Mobile:</strong> ${escapeHtml(w.mobile_num)} ${w.alt_mobile_num ? `| <strong>Alt:</strong> ${escapeHtml(w.alt_mobile_num)}` : ''}</p>
                    <p style="font-size: 0.85rem; color: var(--text-muted); margin-top: 0.25rem;"><strong>Address:</strong> ${escapeHtml(w.address)}</p>
                    ${w.extra_skills ? `<p style="font-size: 0.85rem; margin-top: 0.35rem;"><strong>Extra Skills:</strong> <span class="text-cyan">${escapeHtml(w.extra_skills)}</span></p>` : ''}
                </div>

                <div style="margin-bottom: 1.25rem;">
                    <h4 style="font-size: 0.9rem; margin-bottom: 0.65rem; text-transform: uppercase; color: var(--text-secondary); letter-spacing: 0.5px;">Material-Wise Output</h4>
                    <div style="background: rgba(0,0,0,0.2); border-radius: var(--radius-md); padding: 0.75rem 1rem; border: 1px solid var(--border-color);">
                        ${materialsHtml}
                    </div>
                </div>

                <div style="margin-bottom: 1.5rem;">
                    <h4 style="font-size: 0.9rem; margin-bottom: 0.65rem; text-transform: uppercase; color: var(--text-secondary); letter-spacing: 0.5px;">Recent Logs</h4>
                    <div style="background: rgba(0,0,0,0.2); border-radius: var(--radius-md); padding: 0.75rem 1rem; border: 1px solid var(--border-color);">
                        ${logsHtml}
                    </div>
                </div>

                <div style="display: flex; gap: 0.75rem; justify-content: flex-end;">
                    <a href="daily_output.php?worker_id=${w.id}" class="btn btn-primary btn-sm">
                        <i class="fa-solid fa-shirt"></i> Open Workstation (${escapeHtml(w.name)})
                    </a>
                </div>
            `;
        }
    } catch (err) {
        modalBody.innerHTML = `<div class="text-rose text-center">Failed to load details.</div>`;
    }
};

function escapeHtml(str) {
    if (!str) return '';
    return str.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
}

function togglePassVisibility(inputId, iconId) {
    const input = document.getElementById(inputId);
    const icon = document.getElementById(iconId);
    if (!input) return;
    if (input.type === 'password') {
        input.type = 'text';
        if (icon) {
            icon.classList.remove('fa-eye');
            icon.classList.add('fa-eye-slash');
        }
    } else {
        input.type = 'password';
        if (icon) {
            icon.classList.remove('fa-eye-slash');
            icon.classList.add('fa-eye');
        }
    }
}
window.togglePassVisibility = togglePassVisibility;

function validateWorkerPassword(val) {
    val = (typeof val === 'string') ? val : '';
    const len = val.length;
    const isValid = len >= 4 && len <= 6;

    const pill = document.getElementById('ruleWorkerLen');
    const icon = document.getElementById('ruleWorkerLenIcon');
    const text = document.getElementById('ruleWorkerLenText');
    const counter = document.getElementById('workerPassCounter');
    const passInput = document.getElementById('workerPasswordInput');

    if (counter) {
        counter.innerText = `${len} / 4-6 chars`;
    }

    if (len === 0) {
        if (pill) {
            pill.style.background = 'rgba(255, 255, 255, 0.05)';
            pill.style.color = 'var(--text-muted, #94a3b8)';
            pill.style.borderColor = 'rgba(255, 255, 255, 0.15)';
        }
        if (icon) icon.className = 'fa-regular fa-circle';
        if (text) text.innerText = 'Must be 4 to 6 characters';
        if (counter) counter.style.color = 'var(--text-muted, #94a3b8)';
        if (passInput) {
            passInput.style.borderColor = '';
            passInput.style.boxShadow = '';
        }
    } else if (isValid) {
        if (pill) {
            pill.style.background = 'rgba(16, 185, 129, 0.15)';
            pill.style.color = '#10b981';
            pill.style.borderColor = 'rgba(16, 185, 129, 0.35)';
        }
        if (icon) icon.className = 'fa-solid fa-circle-check';
        if (text) text.innerText = 'Password Valid (4 to 6 characters)';
        if (counter) counter.style.color = '#10b981';
        if (passInput) {
            passInput.style.borderColor = '#10b981';
            passInput.style.boxShadow = '0 0 0 3px rgba(16, 185, 129, 0.2)';
        }
    } else if (len < 4) {
        if (pill) {
            pill.style.background = 'rgba(239, 68, 68, 0.12)';
            pill.style.color = '#ef4444';
            pill.style.borderColor = 'rgba(239, 68, 68, 0.25)';
        }
        if (icon) icon.className = 'fa-solid fa-circle-xmark';
        const remaining = 4 - len;
        if (text) text.innerText = `Too short (need ${remaining} more character${remaining > 1 ? 's' : ''})`;
        if (counter) counter.style.color = '#ef4444';
        if (passInput) {
            passInput.style.borderColor = '#ef4444';
            passInput.style.boxShadow = '0 0 0 2px rgba(239, 68, 68, 0.2)';
        }
    } else {
        // len > 6
        if (pill) {
            pill.style.background = 'rgba(239, 68, 68, 0.12)';
            pill.style.color = '#ef4444';
            pill.style.borderColor = 'rgba(239, 68, 68, 0.25)';
        }
        if (icon) icon.className = 'fa-solid fa-circle-xmark';
        if (text) text.innerText = 'Too long (maximum 6 characters allowed)';
        if (counter) counter.style.color = '#ef4444';
        if (passInput) {
            passInput.style.borderColor = '#ef4444';
            passInput.style.boxShadow = '0 0 0 2px rgba(239, 68, 68, 0.2)';
        }
    }
}
window.validateWorkerPassword = validateWorkerPassword;

function generateRandomPassword() {
    const nums = '0123456789';
    let pass = 'Pass' + nums.charAt(Math.floor(Math.random() * nums.length)) + nums.charAt(Math.floor(Math.random() * nums.length));
    const input = document.getElementById('workerPasswordInput');
    if (input) {
        input.value = pass;
        input.type = 'text';
        const icon = document.getElementById('regWorkerEyeIcon');
        if (icon) {
            icon.classList.remove('fa-eye');
            icon.classList.add('fa-eye-slash');
        }
        validateWorkerPassword(pass);
        if (typeof showToast === 'function') {
            showToast(`Auto-generated 6-character password: ${pass}`, 'info');
        }
    }
}
window.generateRandomPassword = generateRandomPassword;

function updateRoleBadge(role) {
    const badge = document.getElementById('roleAccessBadge');
    const icon = document.getElementById('roleAccessIcon');
    const text = document.getElementById('roleAccessText');
    const tier = document.getElementById('roleAccessTier');
    const desc = document.getElementById('roleDescText');
    const select = document.getElementById('systemRoleSelect');

    if (!badge || !icon || !text) return;

    let tierVal = role ? String(role).toLowerCase() : 'operator';
    let customDesc = null;

    if (select && select.selectedOptions && select.selectedOptions[0]) {
        const opt = select.selectedOptions[0];
        if (opt.dataset && opt.dataset.tier) {
            tierVal = opt.dataset.tier.toLowerCase();
        }
        if (opt.dataset && opt.dataset.desc) {
            customDesc = opt.dataset.desc;
        }
    }

    if (tierVal === 'admin') {
        badge.style.background = 'rgba(239, 68, 68, 0.14)';
        badge.style.color = '#f87171';
        badge.style.borderColor = 'rgba(239, 68, 68, 0.3)';
        icon.className = 'fa-solid fa-crown';
        text.innerText = 'Full Factory Access & Config';
        if (tier) { tier.innerText = 'Admin Scope'; tier.style.color = '#f87171'; tier.style.borderColor = 'rgba(239, 68, 68, 0.35)'; tier.style.background = 'rgba(239, 68, 68, 0.1)'; }
        if (desc) desc.innerText = customDesc || 'Full access to user management, configurations, units, and all system dashboards.';
    } else if (tierVal === 'supervisor') {
        badge.style.background = 'rgba(245, 158, 11, 0.14)';
        badge.style.color = '#fbbf24';
        badge.style.borderColor = 'rgba(245, 158, 11, 0.3)';
        icon.className = 'fa-solid fa-user-gear';
        text.innerText = 'Unit Management & Approvals';
        if (tier) { tier.innerText = 'Supervisor Scope'; tier.style.color = '#fbbf24'; tier.style.borderColor = 'rgba(245, 158, 11, 0.35)'; tier.style.background = 'rgba(245, 158, 11, 0.1)'; }
        if (desc) desc.innerText = customDesc || 'Authorized for shift approvals, unit management, and team production oversight.';
    } else {
        // default operator
        badge.style.background = 'rgba(6, 182, 212, 0.12)';
        badge.style.color = 'var(--accent-cyan)';
        badge.style.borderColor = 'rgba(6, 182, 212, 0.25)';
        icon.className = 'fa-solid fa-laptop-code';
        text.innerText = 'Workstation & IoT Only';
        if (tier) { tier.innerText = 'Operator Scope'; tier.style.color = 'var(--accent-cyan)'; tier.style.borderColor = 'rgba(6, 182, 212, 0.25)'; tier.style.background = 'rgba(6, 182, 212, 0.1)'; }
        if (desc) desc.innerText = customDesc || 'Controls user dashboard privileges and system access permissions.';
    }
}
window.updateRoleBadge = updateRoleBadge;
