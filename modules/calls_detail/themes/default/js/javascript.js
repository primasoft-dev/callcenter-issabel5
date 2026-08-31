$(document).ready(function() {
    /* Namespaced, and unbound first, so that a second execution of this file
     * replaces the handler instead of adding another one. Two handlers on the
     * same element toggled the class twice per click and cancelled out.
     * ES: Con espacio de nombres, y se desasocia primero, para que una segunda
     * ejecucion de este archivo reemplace el manejador en vez de agregar otro.
     * Dos manejadores en el mismo elemento alternaban la clase dos veces por
     * clic y se anulaban entre si. */
    $('div.callcenter-recordings > div:first-child')
        .off('click.ccrecordings')
        .on('click.ccrecordings', function () {
            // Ocultar o mostrar items segun la clase | EN: hide or show items by class
            $(this).parent().toggleClass('collapsed');
        });

    // Close when clicking outside modal
    window.onclick = function(event) {
        var modal = document.getElementById("myModal");
        if (modal && event.target == modal) {
            closeModal();
        }
    };
});

var audioPlayer = null;

function playaudio(URL) {
    var xmlHttp = new XMLHttpRequest();
    xmlHttp.open("GET", URL, false);
    xmlHttp.send(null);
    temp_url = xmlHttp.responseText;
    var file = temp_url.replace(/&amp;/g, '&');

    // Extract filename from URL for display
    var urlParams = new URLSearchParams(file);
    var namefile = urlParams.get('namefile') || urlParams.get('id') || 'Recording';

    var modal = document.getElementById("myModal");
    if (!modal) {
        return;
    }

    modal.style.display = "block";
    $('#myModal').css('top', $(window).scrollTop());

    // Update song name
    var songNameEl = document.querySelector('.song-name');
    if (songNameEl) {
        songNameEl.textContent = namefile;
    }

    // Stop any existing audio
    if (audioPlayer) {
        audioPlayer.pause();
        audioPlayer = null;
    }

    // Create or reuse audio element
    var audio = document.getElementById('call-audio-player');
    if (!audio) {
        audio = document.createElement('audio');
        audio.id = 'call-audio-player';
        audio.style.display = 'none';
        document.body.appendChild(audio);
    }
    audioPlayer = audio;
    audio.src = file;

    // Get UI elements
    var playPauseBtn = document.getElementById("play-pause");
    var slider = document.querySelector('.amplitude-song-slider');
    var currentMinsEl = document.querySelector('.amplitude-current-minutes');
    var currentSecsEl = document.querySelector('.amplitude-current-seconds');
    var durationMinsEl = document.querySelector('.amplitude-duration-minutes');
    var durationSecsEl = document.querySelector('.amplitude-duration-seconds');

    // Reset displays
    if (currentMinsEl) currentMinsEl.textContent = '00';
    if (currentSecsEl) currentSecsEl.textContent = '00';
    if (durationMinsEl) durationMinsEl.textContent = '00';
    if (durationSecsEl) durationSecsEl.textContent = '00';
    if (slider) slider.value = 0;

    // Prevent spacebar from scrolling page
    window.onkeydown = function(e) {
        if (e.keyCode == 32 && document.activeElement !== slider) {
            e.preventDefault();
            togglePlay();
            return false;
        }
    };

    // Play/Pause button handler
    function togglePlay() {
        if (audio.paused) {
            audio.play().catch(function(error) {
                console.error("Play error:", error);
            });
        } else {
            audio.pause();
        }
    }

    if (playPauseBtn) {
        // Remove old event listeners by cloning
        var newBtn = playPauseBtn.cloneNode(true);
        playPauseBtn.parentNode.replaceChild(newBtn, playPauseBtn);
        playPauseBtn = newBtn;

        playPauseBtn.onclick = function(e) {
            e.preventDefault();
            togglePlay();
        };
    }

    // Update time display helper
    function formatTime(seconds) {
        var mins = Math.floor(seconds / 60);
        var secs = Math.floor(seconds % 60);
        return {
            mins: mins.toString().padStart(2, '0'),
            secs: secs.toString().padStart(2, '0')
        };
    }

    // Audio loaded metadata
    audio.onloadedmetadata = function() {
        var time = formatTime(audio.duration);
        if (durationMinsEl) durationMinsEl.textContent = time.mins;
        if (durationSecsEl) durationSecsEl.textContent = time.secs;
        if (slider) {
            slider.max = 100;
            slider.min = 0;
            slider.value = 0;
            slider.disabled = false;

            // Debug slider dimensions
            console.log("=== Slider Debug ===");
            console.log("Slider offsetWidth:", slider.offsetWidth);
            console.log("Slider clientWidth:", slider.clientWidth);
            console.log("Container width:", slider.parentElement.offsetWidth);
            console.log("Slider getBoundingClientRect:", slider.getBoundingClientRect());
        }
    };

    // Update progress during playback
    audio.ontimeupdate = function() {
        if (audio.duration) {
            var percent = (audio.currentTime / audio.duration) * 100;
            if (slider) {
                slider.value = percent;
                // Update slider background with progress
                var val = (slider.value - slider.min) / (slider.max - slider.min) * 100;
                slider.style.background = 'linear-gradient(to right, rgba(255,255,255,0.9) 0%, rgba(255,255,255,0.9) ' + val + '%, rgba(255,255,255,0.2) ' + val + '%, rgba(255,255,255,0.2) 100%)';
            }

            var current = formatTime(audio.currentTime);
            if (currentMinsEl) currentMinsEl.textContent = current.mins;
            if (currentSecsEl) currentSecsEl.textContent = current.secs;
        }
    };

    // Slider input handler (while dragging)
    if (slider) {
        slider.oninput = function() {
            if (audio.duration) {
                var time = (this.value / 100) * audio.duration;
                audio.currentTime = time;
                var val = (this.value - this.min) / (this.max - this.min) * 100;
                this.style.background = 'linear-gradient(to right, rgba(255,255,255,0.9) 0%, rgba(255,255,255,0.9) ' + val + '%, rgba(255,255,255,0.2) ' + val + '%, rgba(255,255,255,0.2) 100%)';
            }
        };
    }

    // Update play/pause button icon
    audio.onplay = function() {
        if (playPauseBtn) {
            playPauseBtn.className = "amplitude-play-pause amplitude-playing";
        }
    };

    audio.onpause = function() {
        if (playPauseBtn && audio.currentTime > 0 && !audio.ended) {
            playPauseBtn.className = "amplitude-play-pause";
        }
    };

    audio.onended = function() {
        if (playPauseBtn) {
            playPauseBtn.className = "amplitude-play-pause";
        }
        // Reset to beginning but don't close
        audio.currentTime = 0;
        if (slider) slider.value = 0;
    };

    // Start playing
    audio.play().catch(function(error) {
        console.error("Initial play error:", error);
    });
}

function closeModal() {
    var modal = document.getElementById("myModal");
    if (modal) {
        modal.style.display = "none";
        // Stop HTML5 audio player
        var audio = document.getElementById('call-audio-player');
        if (audio) {
            audio.pause();
            audio.currentTime = 0;
            audio.src = '';
        }
    }
}
