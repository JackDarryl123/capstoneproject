<div class="modal fade" id="systemCheckModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 bg-transparent">
            
            <div class="relative bg-white rounded-2xl shadow-2xl overflow-hidden transform transition-all border border-gray-100">
                
                <div class="absolute top-0 left-0 w-full h-32 bg-gradient-to-br from-indigo-600 via-purple-600 to-blue-500"></div>
                
                <div class="absolute top-0 right-0 p-4 z-20">
                    <button type="button" class="text-white/80 hover:text-white transition-colors bg-white/10 hover:bg-white/20 rounded-full p-2" data-bs-dismiss="modal">
                        <i class="fas fa-times text-lg w-5 h-5 flex items-center justify-center"></i>
                    </button>
                </div>

                <div class="relative pt-12 px-6 text-center">
                    <div class="mx-auto w-24 h-24 bg-white rounded-full flex items-center justify-center shadow-xl mb-4 relative z-10">
                        <div class="w-20 h-20 bg-indigo-50 rounded-full flex items-center justify-center animate-pulse">
                            <i class="fas fa-server text-4xl text-transparent bg-clip-text bg-gradient-to-br from-indigo-600 to-purple-600"></i>
                        </div>
                        <div class="absolute inset-0 rounded-full border-2 border-dashed border-indigo-300 animate-[spin_8s_linear_infinite]"></div>
                    </div>
                </div>

                <div class="px-8 pb-8 text-center relative z-10">
                    <h3 class="text-2xl font-bold text-gray-800 mb-2">System Health</h3>
                    <div class="h-1 w-12 bg-gradient-to-r from-indigo-500 to-purple-500 mx-auto rounded-full mb-5"></div>
                    
                    <p class="text-gray-500 mb-6 text-sm leading-relaxed">
                        We are currently building a real-time dashboard to monitor server CPU, database latency, and cache efficiency.
                    </p>

                    <div class="bg-indigo-50/50 rounded-xl p-4 border border-indigo-100 mb-6 text-left flex items-start gap-3">
                        <div class="mt-1 bg-white p-1.5 rounded-lg shadow-sm text-indigo-500">
                            <i class="fas fa-rocket text-sm"></i>
                        </div>
                        <div>
                            <span class="block text-sm font-bold text-gray-800">Coming Soon</span>
                            <span class="text-xs text-gray-600">Live analytics and automated error reporting.</span>
                        </div>
                    </div>

                    <button type="button" data-bs-dismiss="modal" 
                        class="w-full py-3 px-6 rounded-xl bg-gray-900 text-white font-medium shadow-lg hover:shadow-xl hover:bg-black hover:-translate-y-0.5 transition-all duration-300 group">
                        <span>Notify me when ready</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>