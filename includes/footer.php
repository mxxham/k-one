    <?php if (Auth::check()): ?>
            </main>
        </div>
    </div>
    <?php else: ?>
    </div>
    <?php endif; ?>

    
    <script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>

    <style>
        @media (max-width: 768px) {
            main { padding: 12px !important; }
            .container-fluid { padding-left: 0 !important; padding-right: 0 !important; }
        }
    </style>

    <script>
        AOS.init({
            duration: 600,
            easing: 'ease-in-out',
            once: true
        });

        
        setTimeout(function() {
            const alerts = document.querySelectorAll('.alert-auto-hide');
            alerts.forEach(alert => {
                alert.style.transition = 'opacity 0.5s';
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 500);
            });
        }, 5000);

        

        
        function formatNumber(num) {
            return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ",");
        }
    </script>
</body>
</html>
