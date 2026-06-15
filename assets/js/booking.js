document.addEventListener("DOMContentLoaded", () => {
    
    // --- 1. Mobile Hamburger Menu Logic ---
    const hamburger = document.getElementById('hamburger');
    const navLinks = document.getElementById('nav-links');
    
    if (hamburger && navLinks) {
        const navItems = navLinks.querySelectorAll('a');

        hamburger.addEventListener('click', () => {
            hamburger.classList.toggle('active');
            navLinks.classList.toggle('active');
            if (navLinks.classList.contains('active')) {
                document.body.style.overflow = 'hidden';
            } else {
                document.body.style.overflow = 'auto';
            }
        });

        navItems.forEach(item => {
            item.addEventListener('click', () => {
                hamburger.classList.remove('active');
                navLinks.classList.remove('active');
                document.body.style.overflow = 'auto';
            });
        });
    }

    // --- 2. Reveal Logic for Footer ---
    const reveals = document.querySelectorAll('.reveal');
    const revealOnScroll = () => {
        const windowHeight = window.innerHeight;
        const elementVisible = 100;
        reveals.forEach(reveal => {
            const elementTop = reveal.getBoundingClientRect().top;
            if (elementTop < windowHeight - elementVisible) {
                reveal.classList.add('active');
            }
        });
    };
    window.addEventListener('scroll', revealOnScroll);
    revealOnScroll();

    // --- 3. Dynamic Venue Image Logic (For ALL Tabs) ---
    const venueSelector = document.getElementById('venue-selector');
    const venueImageDisplay = document.getElementById('venue-image-display');
    
    const hotelSelector = document.getElementById('hotel-selector');
    const hotelImageDisplay = document.getElementById('hotel-image-display');
    
    const villaSelector = document.getElementById('villa-selector');
    const villaImageDisplay = document.getElementById('villa-image-display');

    const imageMaps = {
        venue: {
            'main': 'https://images.unsplash.com/photo-1519225421980-715cb0215aed?ixlib=rb-4.0.3&auto=format&fit=crop&w=1200&q=80',
            'sunset': 'https://images.unsplash.com/photo-1533105079780-92b9be482077?ixlib=rb-4.0.3&auto=format&fit=crop&w=1200&q=80',
            'garden': 'https://images.unsplash.com/photo-1464366400600-7168b8af9bc3?ixlib=rb-4.0.3&auto=format&fit=crop&w=1200&q=80'
        },
        hotel: {
            'deluxe': 'https://images.unsplash.com/photo-1566665797739-1674de7a421a?ixlib=rb-4.0.3&auto=format&fit=crop&w=1200&q=80',
            'vip': 'https://images.unsplash.com/photo-1631049307264-da0ec9d70304?ixlib=rb-4.0.3&auto=format&fit=crop&w=1200&q=80',
            'standard': 'https://images.unsplash.com/photo-1590490360182-c33d57733427?ixlib=rb-4.0.3&auto=format&fit=crop&w=1200&q=80'
        },
        villa: {
            'grand': 'https://images.unsplash.com/photo-1582268611958-ebfd161ef9cf?ixlib=rb-4.0.3&auto=format&fit=crop&w=1200&q=80',
            'oceanfront': 'https://images.unsplash.com/photo-1522708323590-d24dbb6b0267?ixlib=rb-4.0.3&auto=format&fit=crop&w=1200&q=80'
        }
    };

    // Reusable function to switch images with fade effect
    const setupImageSwitcher = (selector, display, imgMap) => {
        if(selector && display) {
            selector.addEventListener('change', (e) => {
                display.style.opacity = 0;
                setTimeout(() => {
                    display.src = imgMap[e.target.value];
                    display.style.opacity = 1;
                }, 300);
            });
        }
    };

    setupImageSwitcher(venueSelector, venueImageDisplay, imageMaps.venue);
    setupImageSwitcher(hotelSelector, hotelImageDisplay, imageMaps.hotel);
    setupImageSwitcher(villaSelector, villaImageDisplay, imageMaps.villa);

    // --- 4. Tab Switching Logic (Left Form & Right Summary) ---
    const tabItems = document.querySelectorAll('.tab-item');
    const tabContents = document.querySelectorAll('.tab-content');
    const summaryContainers = document.querySelectorAll('.summary-container');

    tabItems.forEach(tab => {
        tab.addEventListener('click', () => {
            // Reset active states for Tabs
            tabItems.forEach(t => t.classList.remove('active'));
            tabContents.forEach(c => c.classList.remove('active'));
            
            // Reset active states for Summary Panels
            summaryContainers.forEach(s => s.classList.remove('active'));

            // Set clicked tab to active
            tab.classList.add('active');
            
            // Show corresponding Left Form
            const targetId = tab.getAttribute('data-target');
            document.getElementById(targetId).classList.add('active');

            // Show corresponding Right Summary
            const summaryId = tab.getAttribute('data-summary');
            document.getElementById(summaryId).classList.add('active');
        });
    });

    // --- 5. Toggle Nested Add-ons (Catering & Rooms) ---
    const cateringCheckbox = document.getElementById('addon-catering');
    const cateringDetails = document.getElementById('catering-details');
    const roomsCheckbox = document.getElementById('addon-rooms');
    const roomDetails = document.getElementById('room-details');

    if (cateringCheckbox) {
        cateringCheckbox.addEventListener('change', function() {
            if (this.checked) {
                cateringDetails.style.display = 'block';
                cateringDetails.style.animation = "fadeInTab 0.5s ease";
            } else {
                cateringDetails.style.display = 'none';
            }
        });
    }

    if (roomsCheckbox) {
        roomsCheckbox.addEventListener('change', function() {
            if (this.checked) {
                roomDetails.style.display = 'block';
                roomDetails.style.animation = "fadeInTab 0.5s ease";
            } else {
                roomDetails.style.display = 'none';
            }
        });
    }

    // --- 6. Independent Room Mix & Match Counter ---
    const qtyControls = document.querySelectorAll('.qty-control');
    qtyControls.forEach(control => {
        const minusBtn = control.querySelector('.qty-minus');
        const plusBtn = control.querySelector('.qty-plus');
        const input = control.querySelector('.qty-input');

        minusBtn.addEventListener('click', () => {
            let currentValue = parseInt(input.value);
            if (currentValue > 0) { input.value = currentValue - 1; }
        });

        plusBtn.addEventListener('click', () => {
            let currentValue = parseInt(input.value);
            input.value = currentValue + 1;
        });
    });

    // --- 7. Modal T&C Logic ---
    const modalOverlay = document.getElementById('tc-modal');
    const openTcBtn = document.getElementById('open-tc');
    const agreeBtn = document.getElementById('agree-btn');
    const tcCheckbox = document.getElementById('tc-checkbox');

    if (openTcBtn) {
        openTcBtn.addEventListener('click', (e) => {
            e.preventDefault();
            modalOverlay.classList.add('active');
        });
    }

    if (agreeBtn) {
        agreeBtn.addEventListener('click', () => {
            modalOverlay.classList.remove('active');
            tcCheckbox.checked = true; 
        });
    }

    if (modalOverlay) {
        modalOverlay.addEventListener('click', (e) => {
            if (e.target === modalOverlay) {
                modalOverlay.classList.remove('active');
            }
        });
    }

    // --- 8. Session Countdown Timer ---
    const timerDisplay = document.getElementById('countdown-timer');
    if (timerDisplay) {
        let totalSeconds = 30 * 60; // 30 minutes

        const countdown = setInterval(() => {
            if (totalSeconds <= 0) {
                clearInterval(countdown);
                timerDisplay.textContent = "00:00";
                timerDisplay.style.color = "darkred";
                alert("Your booking session has expired. Please refresh the page to try again.");
                return;
            }

            totalSeconds--;
            
            let minutes = Math.floor(totalSeconds / 60);
            let seconds = totalSeconds % 60;

            minutes = minutes < 10 ? '0' + minutes : minutes;
            seconds = seconds < 10 ? '0' + seconds : seconds;

            timerDisplay.textContent = `${minutes}:${seconds}`;

        }, 1000);
    }
});